<?php

namespace GrocyAI\Controllers\Api;

use Grocy\Controllers\BaseApiController;
use Grocy\Controllers\Users\User;
use Grocy\Services\DatabaseService;
use GrocyAI\Services\GrocyAiShoppingCaptureService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GrocyAiShoppingCaptureController extends BaseApiController
{
	private function fields(array $b, array $required, array $optional=[]):void
	{
		if (array_diff($required, array_keys($b))||array_diff(array_keys($b), [...$required, ...$optional]))
		{
			throw new \InvalidArgumentException('Invalid request fields');
		}
	}
	private function integer(mixed $v, bool $nullable=false):?int
	{
		if ($v===null&&$nullable)
		{
			return null;
		}if (!is_int($v)||$v<1)
		{
			throw new \InvalidArgumentException('Expected a positive integer');
		}return $v;
	}
	private function string(mixed $v):string
	{
		if (!is_string($v)||$v===''||strlen($v)>128)
		{
			throw new \InvalidArgumentException('Expected a bounded string');
		}return $v;
	}
	private function handle(Request $request, Response $response, array $args, string $action):Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST);
		if (!in_array($action, ['read', 'operation', 'options'], true))
		{
			User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);
		}
		if ($action==='curate')
		{
			User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		}
		try
		{
			$id=$args['tripId']??null;
			if (!is_string($id)||!preg_match('/^[1-9][0-9]{0,9}$/D', $id))
			{
				throw new \InvalidArgumentException('Invalid session');
			}$id=(int)$id;
			$op=$args['operationId']??null;
			if (in_array($action, ['operation', 'options', 'settle', 'undo', 'curate'], true)&&(!is_string($op)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $op)))
			{
				throw new \InvalidArgumentException('Invalid operation');
			}
			$b=$request->getParsedBody();
			if ($b===null)
			{
				$b=[];
			}if (!is_array($b))
			{
				throw new \InvalidArgumentException('Invalid JSON body');
			}
			$s=new GrocyAiShoppingCaptureService(DatabaseService::GetInstance()->GetDbConnectionRaw());
			$actor=(string)GROCY_USER_ID;
			switch ($action)
			{
				case 'read':$result=$s->Read($id, $actor);
					$result['permissions']=['collect'=>User::hasPermissions(User::PERMISSION_SHOPPINGLIST_ITEMS_ADD), 'curate'=>User::hasPermissions(User::PERMISSION_MASTER_DATA_EDIT)];
					break;
				case 'select':$this->fields($b, ['list_id'], ['confirm_change']);
					if (isset($b['confirm_change'])&&!is_bool($b['confirm_change']))
					{
						throw new \InvalidArgumentException('Invalid confirmation');
					}$result=$s->SelectList($id, $this->integer($b['list_id'], true), $actor, $b['confirm_change']??false);
					break;
				case 'scan':$this->fields($b, ['operation_id', 'barcode', 'row_id', 'expected_version']);
					$result=$s->Scan($id, $actor, $this->string($b['operation_id']), $this->string($b['barcode']), $this->integer($b['row_id'], true), $b['expected_version']===null ? null : $this->string($b['expected_version']));
					break;
				case 'operation':$result=$s->Operation($id, $actor, $op);
					break;
				case 'options':$result=$s->CurationOptions($id, $actor, $op);
					break;
				case 'settle':$this->fields($b, ['row_id', 'expected_version'], ['amount']);
					$amount=$b['amount']??null;
					if ($amount!==null&&!is_int($amount)&&!is_float($amount))
					{
						throw new \InvalidArgumentException('Invalid amount');
					}$result=$s->Settle($id, $actor, $op, $this->integer($b['row_id']), $this->string($b['expected_version']), $amount);
					break;
				case 'undo':$this->fields($b, []);
					$result=$s->Undo($id, $actor, $op);
					break;
				case 'done':$this->fields($b, ['operation_id', 'row_id', 'expected_version', 'done']);
					if (!is_bool($b['done']))
					{
						throw new \InvalidArgumentException('Invalid done state');
					}$result=$s->SetDone($id, $actor, $this->string($b['operation_id']), $this->integer($b['row_id']), $this->string($b['expected_version']), $b['done']);
					break;
				case 'curate':$this->fields($b, ['mode', 'revision', 'expected_version'], ['product_id', 'fields']);
					$mode=$this->string($b['mode']);
					if (!in_array($mode, ['link', 'create'], true)||($mode==='link')!==array_key_exists('product_id', $b)||($mode==='create')!==array_key_exists('fields', $b)||isset($b['fields'])&&!is_array($b['fields']))
					{
						throw new \InvalidArgumentException('Invalid curation choice');
					}$result=$s->Curate($id, $actor, $op, $this->string($b['expected_version']), $mode, $this->integer($b['revision']), isset($b['product_id']) ? $this->integer($b['product_id']) : null, $b['fields']??null);
					break;
			}
			return $this->ApiResponse($response->withHeader('Cache-Control', 'no-store'), $result);
		}
		catch (\OutOfBoundsException)
		{
			return $this->GenericErrorResponse($response, 'Session, entry or operation not found', 404);
		}
		catch (\InvalidArgumentException $e)
		{
			return $this->GenericErrorResponse($response, $e->getMessage(), 400);
		}
		catch (\RuntimeException|\DomainException $e)
		{
			return $this->GenericErrorResponse($response, $e instanceof \PDOException ? 'Shopping operation unavailable' : $e->getMessage(), 409);
		}
	}
	public function Read(Request $r, Response $p, array $a):Response
	{
		return $this->handle($r, $p, $a, 'read');
	}
	public function Select(Request $r, Response $p, array $a):Response
	{
		return $this->handle($r, $p, $a, 'select');
	}
	public function Scan(Request $r, Response $p, array $a):Response
	{
		return $this->handle($r, $p, $a, 'scan');
	}
	public function Operation(Request $r, Response $p, array $a):Response
	{
		return $this->handle($r, $p, $a, 'operation');
	}
	public function Options(Request $r, Response $p, array $a):Response
	{
		return $this->handle($r, $p, $a, 'options');
	}
	public function Settle(Request $r, Response $p, array $a):Response
	{
		return $this->handle($r, $p, $a, 'settle');
	}
	public function Undo(Request $r, Response $p, array $a):Response
	{
		return $this->handle($r,$p,$a,'undo');
	}
	public function Done(Request $r,Response $p,array $a):Response
	{
		return $this->handle($r,$p,$a,'done');
	}
	public function Curate(Request $r,Response $p,array $a):Response
	{
		return $this->handle($r,$p,$a,'curate');
	}
}
