<?php

namespace Joespark\ApiResourceSearch;

use Illuminate\Support\Str;

class ResourceSearch
{
    private $totalData;
    private $totalFiltered;
    private $data;

    /**
     * @param Illuminate\Database\Eloquent\Builder|Illuminate\Database\Query\Builder $queryBuilder
     * @param array $options
     */
    public function __construct($queryBuilder, $options) {
        $options = array_merge([
            'searches' => [],
            'searches_callback' => $this->getDefaultSearchCallback(),
            'search_query' => null,
            'searchable_columns' => [],
            'order_by' => 'created_at',
            'order_dir' => 'desc',
            'limit' => 0,
            'offset' => 0,
        ], $options);

        $this->totalData = $queryBuilder->count();

        $queryBuilder = $queryBuilder
            ->when($options['search_query'], function ($query) use ($options) {
                return $query->where(function ($query) use ($options) {
                    $handleSearch = function (&$query, $key, $searchQuery, $insideRelation, $model) use (&$handleSearch) {
                        if (strpos($key, '.') !== false) {
                            $keyComposition = explode('.', $key);
                            $relation = $keyComposition[0];
                            $key = Str::after($key, '.');

                            $model = $model->$relation()->getRelated();

                            if ($insideRelation) {
                                $query->whereHas($relation, function ($query) use ($key, $searchQuery, $handleSearch, $model) {
                                    $handleSearch($query, $key, $searchQuery, true, $model);
                                });
                            } else {
                                $query->orWhereHas($relation, function ($query) use ($key, $searchQuery, $handleSearch, $model) {
                                    $handleSearch($query, $key, $searchQuery, true, $model);
                                });
                            }
                        } else {
                            $key = $model->getTable() . '.' . $key;
                            
                            if ($insideRelation) {
                                $query->whereRaw('LOWER(' . $key . '::text) LIKE ?', ['%' . strtolower($searchQuery) . '%']);
                            } else {
                                $query->orWhereRaw('LOWER(' . $key . '::text) LIKE ?', ['%' . strtolower($searchQuery) . '%']);
                            }
                        }
                    };

                    foreach ($options['searchable_columns'] as $column) {
                        $handleSearch($query, $column, $options['search_query'], false, $query->getModel());
                    }
                });
            });

        if ($options['searches_callback']) {
            $queryBuilder = $options['searches_callback']($queryBuilder, $options['searches']);
        } else {
            $queryBuilder = $queryBuilder->when($options['searches'], function ($query) use ($options) {
                return $query->where(function ($query) use ($options) {
                    foreach ($options['searches'] as $column => $value) {
                        $query->where($column, $value);
                    }
                });
            });
        }

        $this->totalFiltered = $queryBuilder->count();

        if ($options['limit'] > 0) {
            $queryBuilder = $queryBuilder->limit($options['limit']);
        }
        
        $model = $queryBuilder->getModel();
        $queryBuilder->select($model->getTable() . ".*");

        $orderBy = $model->getTable() . "." . $options['order_by'];

        $orderByCopy = $options['order_by'];
        while (strpos($orderByCopy, '.') !== false) {
            $keyComposition = explode('.', $orderByCopy);
            $relation = $keyComposition[0];
            $orderByCopy = Str::after($orderByCopy, '.');

            $modelTable = $model->getTable();
            $relationTableName = $model->{$relation}()->getRelated()->getTable();
            $relationTableKey = $model->{$relation}()->getRelated()->getKeyName();
            $relationForeignKey = $model->{$relation}()->getForeignKeyName();

            $queryBuilder->join($relationTableName, $relationTableName . '.' . $relationTableKey, '=', $modelTable . '.' . $relationForeignKey);

            $orderBy = $relationTableName . '.' . $orderByCopy;
            $model = $model->{$relation}()->getRelated();
        }

        $queryBuilder->orderBy($orderBy, $options['order_dir']);

        $this->data = $queryBuilder
            ->offset($options['offset'])
            ->get();
    }

    public function getDefaultSearchCallback()
    {
        $handleSearch = function (&$query, $key, $searchQuery) use (&$handleSearch) {
            if (strpos($key, '.') !== false) {
                $keyComposition = explode('.', $key);
                $relation = $keyComposition[0];
                $key = Str::after($key, '.');

                $query->whereHas($relation, function ($query) use ($key, $searchQuery, $handleSearch) {
                    $handleSearch($query, $key, $searchQuery);
                });
            } else {
                $query->where($key, 'like', '%' . $searchQuery . '%');
            }
        };

        return function ($query, $searches) use ($handleSearch) {
            foreach ($searches as $key => $searchQuery) {
                $handleSearch($query, $key, $searchQuery);
            }

            return $query;
        };
    }

    public function getData()
    {
        return $this->data;
    }

    public function getTotalData()
    {
        return $this->totalData;
    }

    public function getTotalFiltered()
    {
        return $this->totalFiltered;
    }
}
