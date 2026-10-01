<?php

namespace GrocyAI\Services;

use InvalidArgumentException;
use PDO;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

class GrocyAiReceiptImageStore
{
	private const MAX_BYTES = 10 * 1024 * 1024;
	private const MAX_PIXELS = 16000000;
	private PDO $Db;
	private string $DataPath;

	public function __construct(?PDO $pdo = null, ?string $dataPath = null)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		$this->DataPath = rtrim($dataPath ?? GROCY_DATAPATH, '/');
		GrocyAiReceiptMigration::Bootstrap($this->Db);
	}

	public function Save(int $tripId, UploadedFileInterface $image, ?string $requestId = null, ?string $actor = null): array
	{
		if ($this->Db->inTransaction()) throw new RuntimeException('Receipt upload requires its own transaction');
		$this->AssertTrip($tripId);
		if ($requestId !== null && (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $requestId)))
		{
			throw new InvalidArgumentException('Invalid receipt upload request ID');
		}
		if ($requestId !== null && ($existing = $this->FindRequest($tripId, $requestId)) !== null) return $existing;
		if ($image->getError() !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Receipt upload failed');
		$size = $image->getSize();
		if ($size !== null && ($size < 1 || $size > self::MAX_BYTES)) throw new InvalidArgumentException('Receipt image size is invalid');
		$stream = $image->getStream();
		$bytes = '';
		while (!$stream->eof())
		{
			$part = $stream->read(min(65536, self::MAX_BYTES + 1 - strlen($bytes)));
			if ($part === '' && !$stream->eof()) throw new RuntimeException('Receipt image stream stalled');
			$bytes .= $part;
			if (strlen($bytes) > self::MAX_BYTES) throw new InvalidArgumentException('Receipt image is too large');
		}
		if ($bytes === '') throw new InvalidArgumentException('Receipt image is empty');
		$info = @getimagesizefromstring($bytes);
		$mime = $info['mime'] ?? null;
		if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $image->getClientMediaType() !== $mime)
		{
			throw new InvalidArgumentException('Receipt image type is invalid');
		}
		if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS)
		{
			throw new InvalidArgumentException('Receipt image dimensions are invalid');
		}
		$decoded = @imagecreatefromstring($bytes);
		if ($decoded === false) throw new InvalidArgumentException('Receipt image cannot be decoded');
		imagedestroy($decoded);
		$directory = $this->Directory($tripId);
		$this->EnsurePrivateDirectory($directory);
		$imageId = bin2hex(random_bytes(24));
		$path = $directory . '/' . $imageId;
		$temp = tempnam($directory, '.upload-');
		if ($temp === false) throw new RuntimeException('Cannot create receipt image file');
		$renamed = false;
		$writeLocked = false;
		try
		{
			if (file_put_contents($temp, $bytes) !== strlen($bytes)) throw new RuntimeException('Cannot write receipt image');
			chmod($temp, 0600);
			if (!rename($temp, $path)) throw new RuntimeException('Cannot store receipt image');
			$renamed = true;
			$this->Db->exec('BEGIN IMMEDIATE');
			$writeLocked = true;
			$this->AssertTrip($tripId);
			$statement = $this->Db->prepare('INSERT INTO grocy_ai_receipts (trip_id, request_id, image_id, mime_type, image_bytes) VALUES (?, ?, ?, ?, ?)');
			$statement->execute([$tripId, $requestId, $imageId, $mime, strlen($bytes)]);
			$id = (int)$this->Db->lastInsertId();
			$this->Db->prepare('INSERT INTO grocy_ai_receipt_audit (trip_id, receipt_id, actor, action) VALUES (?, ?, ?, ?)')->execute([$tripId, $id, $actor ?? 'unknown', 'upload']);
			$this->Db->exec('COMMIT');
			$writeLocked = false;
			return ['receipt_id' => $id, 'image_id' => $imageId, 'mime_type' => $mime, 'image_bytes' => strlen($bytes)];
		}
		catch (\Throwable $ex)
		{
			if ($writeLocked) $this->Db->exec('ROLLBACK');
			if ($renamed) unlink($path); else @unlink($temp);
			if ($requestId !== null && ($existing = $this->FindRequest($tripId, $requestId)) !== null) return $existing;
			throw $ex;
		}
	}

	public function Read(int $tripId, string $opaqueId): array
	{
		if ($tripId < 1 || !preg_match('/^[a-f0-9]{48}$/D', $opaqueId)) throw new InvalidArgumentException('Invalid receipt image');
		$statement = $this->Db->prepare('SELECT mime_type, image_bytes FROM grocy_ai_receipts WHERE trip_id = ? AND image_id = ?');
		$statement->execute([$tripId, $opaqueId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		if ($row === false) throw new InvalidArgumentException('Receipt image not found');
		$path = $this->Directory($tripId) . '/' . $opaqueId;
		if (!is_file($path) || is_link($path)) throw new RuntimeException('Receipt image unavailable');
		$bytes = file_get_contents($path);
		if ($bytes === false || strlen($bytes) !== (int)$row['image_bytes']) throw new RuntimeException('Receipt image unavailable');
		return ['bytes' => $bytes, 'mime_type' => $row['mime_type']];
	}

	private function AssertTrip(int $tripId): void
	{
		if ($tripId < 1) throw new InvalidArgumentException('Invalid capture trip');
		$statement = $this->Db->prepare('SELECT status FROM grocy_ai_capture_trips WHERE id = ?');
		$statement->execute([$tripId]);
		$status = $statement->fetchColumn();
		if ($status === false || $status === 'committed') throw new InvalidArgumentException('Capture trip is unavailable for receipt upload');
	}

	private function FindRequest(int $tripId, string $requestId): ?array
	{
		$statement = $this->Db->prepare('SELECT id, image_id, mime_type, image_bytes FROM grocy_ai_receipts WHERE trip_id = ? AND request_id = ?');
		$statement->execute([$tripId, $requestId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : ['receipt_id' => (int)$row['id'], 'image_id' => $row['image_id'], 'mime_type' => $row['mime_type'], 'image_bytes' => (int)$row['image_bytes']];
	}

	private function Directory(int $tripId): string
	{
		return $this->DataPath . '/grocy_ai/receipts/' . $tripId;
	}

	private function EnsurePrivateDirectory(string $directory): void
	{
		$path = $this->DataPath;
		foreach (['grocy_ai', 'receipts', basename($directory)] as $segment)
		{
			$path .= '/' . $segment;
			if (is_link($path)) throw new RuntimeException('Receipt storage path is unsafe');
			if (!is_dir($path) && !mkdir($path, 0700)) throw new RuntimeException('Cannot create receipt storage directory');
			chmod($path, 0700);
		}
	}
}
