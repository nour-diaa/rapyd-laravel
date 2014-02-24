<?php

namespace Zofe\Rapyd;

use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Paginator;
use Zofe\Rapyd\Exceptions\DataSetException;

class DataSet extends Widget
{

    public $cid;
    public $source;

    /**
     *
     * @var \Illuminate\Database\Query\Builder
     */
    public $query;
    public $data = array();
    public $hash = '';
    public $url;

    /**
     * @var \Illuminate\Pagination\Paginator
     */
    public $paginator;
    protected $orderby_field;
    protected $orderby_direction;
    protected $type;
    protected $limit;
    protected $orderby;
    public $total_rows;
    protected $orderby_uri_asc;
    protected $orderby_uri_desc;

    /**
     * @param $source
     *
     * @return static
     */
    public static function source($source)
    {
        $ins = new static;
        $ins->source = $source;

        //inherit cid from datafilter
        if ($ins->source instanceof \Zofe\Rapyd\DataFilter\DataFilter) {
            $ins->cid = $ins->source->cid;
        }
        //generate new component id
        else {
            $ins->cid = $ins->getIdentifier();
        }

        return $ins;
    }

    protected function table($table)
    {
        $this->query = DB::table($table);
        return $this->query;
    }

    /**
     * @param        $field
     * @param string $dir
     *
     * @return mixed
     */
    public function orderbyLink($field, $dir = "asc")
    {
        $url = ($dir == "asc") ? $this->orderby_uri_asc : $this->orderby_uri_desc;
        return str_replace('-field-', $field, $url);
    }

    public function orderby($field, $direction)
    {
        $this->orderby = array($field, $direction);
        return $this;
    }

    /**
     * @param $items
     *
     * @return $this
     */
    public function paginate($items)
    {
        $this->limit = $items;
        return $this;
    }

    public function build()
    {
        if (is_string($this->source) && strpos(" ", $this->source) === false) {
            //tablename
            $this->type = "query";
            $this->query = $this->table($this->source);
        } elseif (is_a($this->source, "\Illuminate\Database\Eloquent\Model") ||
                is_a($this->source, "\Illuminate\Database\Eloquent\Builder") ||
                is_a($this->source, "\Illuminate\Database\Query\Builder")) {
            $this->type = "model";
            $this->query = $this->source;
        } elseif ( is_a($this->source, "\Zofe\Rapyd\DataFilter\DataFilter")) {
           $this->type = "model";
           $this->query = $this->source->query;
        }
        //array
        elseif (is_array($this->source)) {
            $this->type = "array";
        } else {
            throw new DataSetException(' "source" must be a table name, an eloquent model or an eloquent builder. you passed: ' . get_class($this->source));
        }



        //build orderby urls
        $this->orderby_uri_asc = $this->url->remove('page' . $this->cid)->remove('reset' . $this->cid)->append('ord' . $this->cid, "-field-")->get() . $this->hash;

        $this->orderby_uri_desc = $this->url->remove('page' . $this->cid)->remove('reset' . $this->cid)->append('ord' . $this->cid, "--field-")->get() . $this->hash;


        //detect orderby
        $orderby = $this->url->value("ord" . $this->cid);
        if ($orderby) {
            $this->orderby_field = ltrim($orderby, "-");
            $this->orderby_direction = ($orderby[0] === "-") ? "desc" : "asc";
            $this->orderby($this->orderby_field, $this->orderby_direction);
        }

        //build subset of data
        switch ($this->type) {
            case "array":
                //orderby
                if (isset($this->orderby)) {
                    list($field, $direction) = $this->orderby;
                    $column = array();
                    foreach ($this->source as $key => $row) {
                        $column[$key] = $row[$field];
                    }
                    if ($direction == "asc") {
                        array_multisort($column, SORT_ASC, $this->source);
                    } else {
                        array_multisort($column, SORT_DESC, $this->source);
                    }
                }

                // @TODO: must be refactored
                $this->paginator = Paginator::make($this->source, count($this->source), $this->limit ? $this->limit : 1000000);
                //find better way 
                $this->data = array_slice($this->source, $this->paginator->getFrom() - 1, $this->limit);
                break;

            case "query":
            case "model":
                //orderby

                if (isset($this->orderby)) {
                    $this->query = $this->query->orderBy($this->orderby[0], $this->orderby[1]);
                }
                //limit-offset
                if (isset($this->limit)) {
                    $this->paginator = $this->query->paginate($this->limit);
                    $this->data = $this->paginator;
                } else {
                    $this->data = $this->query->get();
                }


                break;
        }
        return $this;
    }

    /**
     * @return $this
     */
    public function getSet()
    {
        $this->build();
        return $this;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param string $view
     *
     * @return mixed
     */
    public function links($view = null)
    {
        if ($this->limit) {
            if ($this->hash != '')
                return $this->paginator->appends($this->url->remove('page')->getArray())->fragment($this->hash)->links($view);
            else
                return $this->paginator->appends($this->url->remove('page')->getArray())->links($view);
        }
    }

    public function havePagination()
    {
        return (bool) $this->limit;
    }

}
