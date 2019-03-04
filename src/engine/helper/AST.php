<?php

namespace placer\brio\engine\helper;

/**
 *  Abstract syntax tree helper class.
 */
class AST
{
    public $stack     = [];
    public $current   = [];
    public $doesPrint = false;

    protected static function checkType($obj, $type)
    {
        if (is_string($obj))
        {
            return false;
        }

        if (is_object($obj))
        {
            $obj = $obj->getArray();
        }

        return isset($obj[$type]);
    }

    public static function isStr($arr)
    {
        return self::checkType($arr, 'string');
    }

    public static function isVar($arr)
    {
        return self::checkType($arr, 'var');
    }

    public static function isExec($arr)
    {
        return self::checkType($arr, 'exec');
    }

    public static function isExpr($arr)
    {
        return self::checkType($arr, 'op_expr');
    }

    public static function str($string)
    {
        return ["string" => $string];
    }

    public static function num($number)
    {
        return ["number" => $number];
    }

    public function stackSize()
    {
        return count($this->stack);
    }

    public function appendAST(AST $obj)
    {
        $this->end();
        $obj->end();
        $this->stack = array_merge($this->stack, $obj->stack);

        return $this;
    }

    public static function constant($str)
    {
        return ['constant' => $str];
    }

    public function comment($str)
    {
        $this->stack[] = ["op" => "comment", 'comment' => $str];

        return $this;
    }

    public function declareFunction($name)
    {
        $this->stack[] = ['op' => 'function', 'name' => $name];

        return $this;
    }

    public function doReturn($name)
    {
        $this->getValue($name, $expr);
        $this->stack[] = ['op' => 'return', $expr];

        return $this;
    }

    public function doIf($expr)
    {
        $this->getValue($expr, $vexpr);
        $this->stack[] = ['op' => 'if', 'expr' => $vexpr];

        return $this;
    }

    public function doElse()
    {
        $this->stack[] = ['op' => 'else'];

        return $this;
    }

    public function doEndif()
    {
        $this->stack[] = ['op' => 'end_if'];

        return $this;
    }

    public function doEndfunction()
    {
        $this->stack[] = ['op' => 'end_function'];

        return $this;
    }

    public function v()
    {
        $var = [];

        foreach (func_get_args() as $id => $def)
        {
            if ($id == 0)
            {
                $var[$id] = $def;
            }
            else
            {
                $this->getValue($def, $value);
                $var[$id] = $value;
            }
        }

        if (count($var) == 1)
        {
            $var = $var[0];
        }

        $this->current = ['var' => $var];

        return $this;
    }

    public static function fromArrayGetAST($obj)
    {
        $class = __CLASS__;

        if ($obj InstanceOf $class)
        {
            return $obj;
        }

        $types = ['op_expr', 'expr_cond', 'exec', 'var', 'string', 'number', 'constant'];

        foreach ($types as $type)
        {
            if (isset($obj[$type]))
            {
                $nobj = new $class;
                $nobj->stack[] = $obj;

                return $nobj;
            }
        }
    }

    public static function getValue($obj, &$value, $getAll=false)
    {
        $class = __CLASS__;

        if ($obj InstanceOf $class)
        {
            $value = $obj->getArray($getAll);
        }
        elseif (is_string($obj))
        {
            $value = self::str($obj);
        }
        elseif (is_numeric($obj) || $obj === 0)
        {
            $value = self::num($obj);
        }
        elseif ($obj === false)
        {
            $value = ['expr' => false];
        }
        elseif ($obj === true)
        {
            $value = ['expr' => true];
        }
        elseif (is_array($obj))
        {
            $types = ['expr_cond', 'op_expr', 'exec', 'var', 'string', 'number', 'constant'];

            foreach ($types as $type)
            {
                if (isset($obj[$type]))
                {
                    $value = $obj;
                    return;
                }
            }

            $h = (new AST)->arr();
            $first = 0;

            foreach ($obj as $key => $value)
            {
                if ($key === $first)
                {
                    $key = null;
                    $first++;
                }
                $h->element($key, $value);
            }
            $value = $h->getArray();
        }
        elseif ($obj === null)
        {
            $value = [];
        }
        else
        {
            throw new Exception("Imposible to get the value of the object");
        }
    }

    public function getArray($getAll=false)
    {
        $this->end();

        if ($getAll)
        {
            return $this->stack;
        }

        return isset($this->stack[0]) ?  $this->stack[0] : null;
    }

    public function doFor($index, $min, $max, $step, AST $body)
    {
        $def = [
            'op'    => 'for',
            'index' => $index,
            'min'   => $min,
            'max'   => $max,
            'step'  => $step,
        ];

        $this->stack[] = $def;
        $this->stack   = array_merge($this->stack, $body->getArray(true));
        $this->stack[] = ['op' => 'end_for'];

        return $this;
    }

    public function doForeach($array, $value, $key, AST $body)
    {
        $vars = ['array', 'value', 'key'];

        foreach ($vars as $var)
        {
            if ($$var === null)
            {
                continue;
            }

            $var1 = & $$var;

            if (is_string($var1))
            {
                $var1 = BH::hvar($var1);
            }

            if (is_object($var1))
            {
                $var1 = $var1->getArray();
            }

            if (empty($var1['var']))
            {
                throw new Exception("Can't iterate, apparently $var isn't a variable");
            }
            $var1 = $var1['var'];
        }

        $def = ['op' => 'foreach', 'array' => $array, 'value' => $value];

        if ($key)
        {
            $def['key'] = $key;
        }

        $this->stack[] = $def;
        $this->stack   = array_merge($this->stack, $body->getArray(true));
        $this->stack[] = ['op' => 'end_foreach'];

        return $this;
    }

    public function doEcho($stmt)
    {
        $this->getValue($stmt, $value);
        $this->stack[] = ['op' => 'print', $value];

        return $this;
    }

    public function doGlobal($array)
    {
        $this->stack[] = ['op' => 'global',  'vars' => $array];

        return $this;
    }

    public function doExec()
    {
        $params = func_get_args();
        $exec = call_user_func_array(['placer\brio\engine\helper\BH', 'hexec'], $params);
        $this->stack[] = ['op' => 'expr', $exec->getArray()];

        return $this;
    }

    public function exec($function)
    {
        $this->current = ['exec' => $function, 'args' => []];

        $fArgs = func_get_args();

        foreach ($fArgs as $id => $param)
        {
            if ($id > 0)
            {
                $this->param($param);
            }
        }

        return $this;
    }

    public function expr($operation, $term1, $term2=null)
    {
        $this->getValue($term1, $value1);

        if ($term2 !== null)
        {
            $this->getValue($term2, $value2);
        }
        else
        {
            $value2 = null;
        }
        $this->current = ['op_expr' => $operation, $value1, $value2];

        return $this;
    }

    public function exprCond($expr, $ifTrue, $ifFalse)
    {
        $this->getValue($expr, $vExpr);
        $this->getValue($ifTrue, $vIfTrue);
        $this->getValue($ifFalse, $vIfFalse);

        $this->current = ['expr_cond' => $vExpr, 'true' => $vIfTrue, 'false' => $vIfFalse];

        return $this;
    }

    public function arr()
    {
        $this->current = ['array' => []];

        return $this;
    }

    public function element($key=null, $value)
    {
        $last = & $this->current;

        if (! isset($last['array']))
        {
            throw new Exception("Invalid call to element()");
        }

        $this->getValue($value, $val);

        if ($key !== null)
        {
            $this->getValue($key, $kval);
            $val = ['key' => [$kval, $val]];
        }

        $last['array'][] = $val;
    }

    public function declRaw($name, $value)
    {
        if (is_string($name))
        {
            $name = BH::hvar($name);
        }

        $this->getValue($name, $name);

        $array = ['op' => 'declare', 'name' => $name['var']];

        $fArgs = func_get_args();

        foreach ($fArgs as $id => $value)
        {
            if ($id != 0)
            {
                $array[] = $value;
            }
        }
        $this->stack[] = $array;

        return $this;
    }

    public function decl($name, $value)
    {
        if (is_string($name))
        {
            $name = BH::hvar($name);
        }

        $this->getValue($name, $name);

        $array = ['op' => 'declare', 'name' => $name['var']];

        $fArgs = func_get_args();

        foreach ($fArgs as $id => $value)
        {
            if ($id != 0)
            {
                $this->getValue($value, $stmt);
                $array[] = $stmt;
            }
        }
        $this->stack[] = $array;

        return $this;
    }

    public function append($name, $value)
    {
        if (is_string($name))
        {
            $name = BH::hvar($name);
        }

        $this->getValue($value, $stmt);
        $this->getValue($name, $name);
        $this->stack[] = ['op' => 'append_var', 'name' => $name['var'], $stmt];

        return $this;
    }

    public function param($param)
    {
        $last = & $this->current;

        if (! isset($last['exec']))
        {
            throw new Exception("Invalid call to param()");
        }

        $this->getValue($param, $value);
        $last['args'][] = $value;

        return $this;
    }

    public function end()
    {
        if (count($this->current) > 0)
        {
            $this->stack[] = $this->current;
            $this->current = [];
        }

        return $this;
    }

    protected function & getLast()
    {
        $f = [];

        if (count($this->stack) == 0)
        {
            return $f;
        }

        return $this->stack[count($this->stack)-1];
    }

    public function __get($property)
    {
        $property = strtolower($property);

        if (isset($this->current[$property]))
        {
            return $this->current[$property];
        }

        return false;
    }

}

