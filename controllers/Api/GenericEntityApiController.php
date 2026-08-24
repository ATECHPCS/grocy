<?php

namespace Grocy\Controllers\Api;

use Grocy\Controllers\Users\User;
use Grocy\Services\StockService;
use Grocy\Services\UserfieldsService;
use Grocy\Services\UsersService;
use GrocyAI\Services\GrocyAiConversionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GenericEntityApiController extends BaseApiController
{
	public function AddObject(Request $request, Response $response, array $args)
	{
		if ($args['entity'] == 'shopping_list' || $args['entity'] == 'shopping_lists')
		{
			User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);
		}
		elseif ($args['entity'] == 'recipes' || $args['entity'] == 'recipes_pos' || $args['entity'] == 'recipes_nestings')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES);
		}
		elseif ($args['entity'] == 'meal_plan')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES_MEALPLAN);
		}
		elseif ($args['entity'] == 'equipment')
		{
			User::CheckPermission($request, User::PERMISSION_EQUIPMENT);
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		}

		if ($this->IsValidExposedEntity($args['entity']) && !$this->IsEntityWithNoEdit($args['entity']))
		{
			if ($this->IsEntityWithEditRequiresAdmin($args['entity']))
			{
				User::CheckPermission($request, User::PERMISSION_ADMIN);
			}

			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			try
			{
				if ($requestBody === null)
				{
					throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
				}

				$this->ValidateQuantityUnitConversionBeforeWrite($args['entity'], $requestBody, null);
				$newRow = $this->DB->{$args['entity']}()->createRow($requestBody);
				$newRow->save();
				$newObjectId = $this->DB->lastInsertId();

				// TODO: This should be better done somehow in StockService
				if ($args['entity'] == 'products' && boolval(UsersService::GetInstance()->GetUserSetting(GROCY_USER_ID, 'shopping_list_auto_add_below_min_stock_amount')))
				{
					StockService::GetInstance()->AddMissingProductsToShoppingList(UsersService::GetInstance()->GetUserSetting(GROCY_USER_ID, 'shopping_list_auto_add_below_min_stock_amount_list_id'));
				}

				return $this->ApiResponse($response, [
					'created_object_id' => $newObjectId
				]);
			}
			catch (\Exception $ex)
			{
				return $this->GenericErrorResponse($response, $ex->getMessage());
			}
		}
		else
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}
	}

	public function DeleteObject(Request $request, Response $response, array $args)
	{
		if ($args['entity'] == 'shopping_list' || $args['entity'] == 'shopping_lists')
		{
			User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_DELETE);
		}
		elseif ($args['entity'] == 'recipes' || $args['entity'] == 'recipes_pos' || $args['entity'] == 'recipes_nestings')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES);
		}
		elseif ($args['entity'] == 'meal_plan')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES_MEALPLAN);
		}
		elseif ($args['entity'] == 'equipment')
		{
			User::CheckPermission($request, User::PERMISSION_EQUIPMENT);
		}
		elseif ($args['entity'] == 'api_keys')
		{
			// Always allowed
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		}

		if ($this->IsValidExposedEntity($args['entity']) && !$this->IsEntityWithNoDelete($args['entity']))
		{
			if ($this->IsEntityWithEditRequiresAdmin($args['entity']))
			{
				User::CheckPermission($request, User::PERMISSION_ADMIN);
			}

			$row = $this->DB->{$args['entity']}($args['objectId']);
			if ($row == null)
			{
				return $this->GenericErrorResponse($response, 'Object not found', 400);
			}

			$row->delete();

			return $this->EmptyApiResponse($response);
		}
		else
		{
			return $this->GenericErrorResponse($response, 'Invalid entity');
		}
	}

	public function EditObject(Request $request, Response $response, array $args)
	{
		if ($args['entity'] == 'shopping_list' || $args['entity'] == 'shopping_lists')
		{
			User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);
		}
		elseif ($args['entity'] == 'recipes' || $args['entity'] == 'recipes_pos' || $args['entity'] == 'recipes_nestings')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES);
		}
		elseif ($args['entity'] == 'meal_plan')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES_MEALPLAN);
		}
		elseif ($args['entity'] == 'equipment')
		{
			User::CheckPermission($request, User::PERMISSION_EQUIPMENT);
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		}

		if ($this->IsValidExposedEntity($args['entity']) && !$this->IsEntityWithNoEdit($args['entity']))
		{
			if ($this->IsEntityWithEditRequiresAdmin($args['entity']))
			{
				User::CheckPermission($request, User::PERMISSION_ADMIN);
			}

			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			try
			{
				if ($requestBody === null)
				{
					throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
				}

				$row = $this->DB->{$args['entity']}($args['objectId']);
				if ($row == null)
				{
					return $this->GenericErrorResponse($response, 'Object not found', 400);
				}

				$this->ValidateQuantityUnitConversionBeforeWrite($args['entity'], $requestBody, (int)$args['objectId']);
				$row->update($requestBody);

				// TODO: This should be better done somehow in StockService
				if ($args['entity'] == 'products' && boolval(UsersService::GetInstance()->GetUserSetting(GROCY_USER_ID, 'shopping_list_auto_add_below_min_stock_amount')))
				{
					StockService::GetInstance()->AddMissingProductsToShoppingList(UsersService::GetInstance()->GetUserSetting(GROCY_USER_ID, 'shopping_list_auto_add_below_min_stock_amount_list_id'));
				}

				return $this->EmptyApiResponse($response);
			}
			catch (\Exception $ex)
			{
				return $this->GenericErrorResponse($response, $ex->getMessage());
			}
		}
		else
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}
	}

	public function GetObject(Request $request, Response $response, array $args)
	{
		if (!$this->IsValidExposedEntity($args['entity']) || $this->IsEntityWithNoListing($args['entity']))
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}

		$object = $this->DB->{$args['entity']}($args['objectId']);
		if ($object == null)
		{
			return $this->GenericErrorResponse($response, 'Object not found', 404);
		}

		// TODO: Handle this somehow more generically
		$referencingId = $args['objectId'];
		if ($args['entity'] == 'stock')
		{
			$referencingId = $object->stock_id;
		}
		$userfields = UserfieldsService::GetInstance()->GetValues($args['entity'], $referencingId);
		if (count($userfields) === 0)
		{
			$userfields = null;
		}
		$object['userfields'] = $userfields;

		return $this->ApiResponse($response, $object);
	}

	public function GetObjects(Request $request, Response $response, array $args)
	{
		if (!$this->IsValidExposedEntity($args['entity']) || $this->IsEntityWithNoListing($args['entity']))
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}

		$objects = $this->QueryData($this->DB->{$args['entity']}(), $request->getQueryParams());

		$userfields = UserfieldsService::GetInstance()->GetFields($args['entity']);
		if (count($userfields) > 0)
		{
			$allUserfieldValues = UserfieldsService::GetInstance()->GetAllValues($args['entity']);

			foreach ($objects as $object)
			{
				$userfieldKeyValuePairs = null;
				foreach ($userfields as $userfield)
				{
					// TODO: Handle this somehow more generically
					$userfieldReference = 'id';
					if ($args['entity'] == 'stock')
					{
						$userfieldReference = 'stock_id';
					}

					$value = FindObjectInArrayByPropertyValue(FindAllObjectsInArrayByPropertyValue($allUserfieldValues, 'object_id', $object->{$userfieldReference}), 'name', $userfield->name);
					if ($value)
					{
						$userfieldKeyValuePairs[$userfield->name] = $value->value;
					}
					else
					{
						$userfieldKeyValuePairs[$userfield->name] = null;
					}
				}

				$object->userfields = $userfieldKeyValuePairs;
			}
		}

		return $this->ApiResponse($response, $objects);
	}

	public function GetUserfields(Request $request, Response $response, array $args)
	{
		try
		{
			return $this->ApiResponse($response, UserfieldsService::GetInstance()->GetValues($args['entity'], $args['objectId']));
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	public function SetUserfields(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		try
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			UserfieldsService::GetInstance()->SetValues($args['entity'], $args['objectId'], $requestBody);
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	private function IsEntityWithEditRequiresAdmin($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityEditRequiresAdmin->enum);
	}

	private function IsEntityWithNoListing($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityNoListing->enum);
	}

	private function IsEntityWithNoEdit($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityNoEdit->enum);
	}

	private function IsEntityWithNoDelete($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityNoDelete->enum);
	}

	private function IsValidExposedEntity($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntity->enum);
	}

	private function ValidateQuantityUnitConversionBeforeWrite(string $entity, array $requestBody, ?int $objectId): void
	{
		if ($entity !== 'quantity_unit_conversions' || !defined('GROCY_FEATURE_FLAG_GROCY_AI') || !GROCY_FEATURE_FLAG_GROCY_AI)
		{
			return;
		}

		try
		{
			if (!class_exists(GrocyAiConversionService::class))
			{
				throw new \RuntimeException('conversion_service_unavailable');
			}
			$result = (new GrocyAiConversionService())->ValidateNativeConversionBeforeWrite($requestBody, $objectId);
		}
		catch (\Throwable)
		{
			throw new \Exception('conversion_write_blocked:validation_unavailable');
		}

		if (($result['status'] ?? null) === 'product_native' && ($result['scope'] ?? null) === 'product' && ($result['blockers'] ?? null) === [])
		{
			return;
		}

		$reason = ($result['status'] ?? null) === 'inactive' ? 'reusable_scope_inactive' : (($result['blockers'][0] ?? null));
		$boundedReasons = [
			'factor_not_finite', 'factor_non_positive', 'unit_not_cataloged', 'reusable_count_scope',
			'inactive_revision_unavailable', 'revision_source_version_invalid', 'catalog_source_version_invalid',
			'catalog_unit_invalid', 'dimension_mismatch', 'stale_revision_identity', 'stale_source_version',
			'catalog_rule_source_version_invalid', 'catalog_rule_invalid', 'competing_path', 'reciprocal_mismatch',
			'cycle_detected', 'factor_tolerance', 'reusable_scope_inactive'
		];
		if (!is_string($reason) || !in_array($reason, $boundedReasons, true))
		{
			$reason = 'validation_failed';
		}
		throw new \Exception('conversion_write_blocked:' . $reason);
	}
}
