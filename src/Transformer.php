<?php

namespace jfadich\JsonResponder;

use jfadich\JsonResponder\Exceptions\InvalidModelRelation;
use jfadich\JsonResponder\Contracts\Transformable;
use League\Fractal\TransformerAbstract;
use Illuminate\Support\Collection;
use League\Fractal\ParamBag;
use BadMethodCallException;

/**
 * Transformers take a Model object and prepare it for output to JSON. It can include nested relations dynamically
 * This is an extension of League\Fractal library
 */
abstract class Transformer extends TransformerAbstract
{
    /**
     * Columns available for sorting result.
     *
     * @var array ['api_name' => 'sql_field']
     */
    protected $orderColumns = [];

    /**
     * Available includes that should not be passed to Eloquent for SQL eager loading.
     *
     * @var array
     */
    protected $lazyIncludes = [];

    /**
     * Default sort columns available for all models.
     *
     * @var array ['api_name' => 'sql_field']
     */
    private $defaultOrderColumns = [
        'created' => 'created_at',
        'updated' => 'updated_at'
    ];

    /**
     * Max number of resources that can be requested
     *
     * @var int
     */
    protected $requestLimit = 1000;

    /**
     * Parse the limit and order parameters
     *
     * @param ParamBag $params
     * @param Transformer $transformer
     * @return array
     */
    public function parseParams(ParamBag $params = null, Transformer $transformer)
    {
        $result = ['limit' => null, 'order' => null];

        if ($params === null) {
            return $result;
        }

        $order = $params->get('order');
        $limit = $params->get(config('transformers.countName'));

        if (is_numeric($limit[0])) {
            $result['limit'] = min($limit[0], $this->requestLimit);
        }

        $availableSortColumns = $this->resolveOrderColumns($transformer);

        if (is_array($order) && count($order) === 2) {
            if (in_array($order[0], array_keys($availableSortColumns)) && in_array($order[1], ['desc', 'asc'])) {
                $order[0] = $availableSortColumns[$order[0]];
                $result['order'] = $order;
            }
        }

        return $result;
    }

    public function getOrderColumns()
    {
        return $this->orderColumns;
    }

    protected function resolveOrderColumns(Transformer $transformer)
    {
        return array_merge($this->defaultOrderColumns, $transformer->getOrderColumns());
    }

    /**
     * Prevent includes from being processed
     *
     * @return $this
     */
    public function disableIncludes()
    {
        $this->availableIncludes = [];

        return $this;
    }

    /**
     * Get the include data from the model.
     *
     * @param $model
     * @param $relation
     * @return Collection|Transformable|null
     */
    protected function resolveInclude($model, $relation)
    {
        $include = $model->$relation;

        if (!$include) {
            return null;
        }

        return $include;
    }

    /**
     * Get the model relation from the method name
     *
     * @param $method
     * @return bool|string
     */
    protected function resolveRelationName($method)
    {
        $relation = camel_case(str_replace('include', '', $method));

        if (!in_array($relation, $this->getAvailableIncludes())) {
            return false;
        }

        return $relation;
    }

    /**
     * Get the transformer for the requested model
     *
     * @param $model
     * @param $relation
     * @return mixed
     * @throws InvalidModelRelation
     */
    protected function getRelatedTransformer($model, $relation)
    {
        if (!method_exists($model, $relation) || !($relationship = $model->$relation())) {
            $class = get_class($model);
            throw new InvalidModelRelation("'$relation' is invalid relation for '$class'");
        }

        if (!($related = $relationship->getRelated()) instanceof Transformable) {
            throw new InvalidModelRelation('Model must be a Transformable instance');
        }

        return $related->getTransformer();
    }

    /**
     * Get an array of includes that are available, but should not be eager loaded with Eloquent.
     *
     * @return array
     */
    public function getLazyIncludes()
    {
        return $this->lazyIncludes;
    }

    /**
     * Magic method to catch include* method calls.
     * Validate the include exists, then return item or collection.
     *
     * @param $method
     * @param $arguments
     * @return \League\Fractal\Resource\Collection|\League\Fractal\Resource\Item|null
     * @throws InvalidModelRelation
     */
    public function __call($method, $arguments)
    {
        $relation = $this->resolveRelationName($method);

        if (!$relation || !($model = $arguments[0]) instanceof Transformable) {
            throw new BadMethodCallException('Invalid include requested on transformer');
        }

        $data = $this->resolveInclude($model, $relation);

        if ($data === null) {
            return null;
        }

        $transformer = $this->getRelatedTransformer($model, $relation);

        if ($data instanceof Collection) {
            $params = $this->parseParams($arguments[1], $transformer);

            if($params['limit'] !== null) {
                $data = $data->take($params['limit']);
            }

            return $this->collection($data, $transformer);
        }

        return $this->item($data, $transformer);
    }
}