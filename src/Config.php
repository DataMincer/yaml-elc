<?php

namespace YamlElc;

class Config {

  /** @var Dimension[] */
  protected $dimensions = [];
  protected $data = [];

  public function filter($filters) {
    $filters = YamlElc::parseFilters($filters, $this->dimensions);
    if (empty($filters)) {
      return;
    }
    foreach ($this->dimensions as $dimension) {
      $dimension->filterItems($filters);
    }
  }

  /**
   * @param Dimension[] $dimensions
   */
  public function setDimensions(array $dimensions) {
    $this->dimensions = $dimensions;
  }

  /**
   * @return Dimension[]
   */
  public function getDimensions() {
    return $this->dimensions;
  }

  /**
   * @param DataMap $data
   */
  public function setData(DataMap $data) {
    $this->data = $data;
  }

  public function getData() {
    return $this->data;
  }

  public function generate() {
    if (count($this->dimensions) > 0) {
      return $this->generateDimensionTuples($this->dimensions);
    }
    else {
      return [[]];
    }
  }

  public function render($tuple) {
    return $this->renderData($this->data, $tuple);
  }

  protected function renderData($data, $tuple) {
    $result = NULL;
    foreach ($data as $item) {
      $key = NULL;
      if ($data instanceof DataMap) {
        list($key, $value, $conditions) = $item;
      }
      else {
        list($value, $conditions) = $item;
      }
      $tests = [];
      foreach ($conditions as $condition) {
        $res = Util::arrayContains($condition, $tuple);
        $tests[] = !empty($res);
      }
      if (!Util::ifAll($tests)) {
        continue;
      }
      if (is_object($value)) {
        $sub_value = $this->renderData($value, $tuple);
        if (!is_null($sub_value)) {
          if ($data instanceof DataMap) {
            // Map
            $result = !is_null($key) ? array_merge($result ?? [], [$key => $sub_value]) : $sub_value;
          }
          else {
            // Array
            $result[] = $sub_value;
          }
        }
      }
      else {
        // Plain value
        $resolved_value = $this->resolveDataValue($value, $tuple);
        if ($data instanceof DataMap) {
          // Map
          $result = !is_null($key) ? array_merge($result ?? [], [$key => $resolved_value]) : $resolved_value;
        }
        else {
          // Array
          $result[] = $resolved_value;
        }
      }
    }
    return is_null($result) && ($data instanceof DataArray || $data instanceof DataMap) ? [] : $result;
  }

  protected function resolveDataValue($value, $tuple, $allow_empty = FALSE) {
    $result = $value;
    if (!is_string($value)) {
      return $result;
    }
    foreach ($this->dimensions as $dimension) {
      $regex =  '~' . preg_quote(implode($dimension->lb()), '~') . '(:*)(@|\$|\.)' . preg_quote(implode($dimension->rb()), '~') . '~';
      $replace_func = function($matches) use ($dimension, $tuple, $allow_empty) {
        $register = 'R' . (count(explode(':', $matches[1])) - 1);
        // Check if this is valid register
        if (!$dimension->hasRegister($register)) {
          throw new YamlElcException("Register '$register' not found on dimension '{$dimension->name()}'");
        }
        switch($matches[2]) {
          case ".": ;
            // Return dimension name
            $result = $dimension->name();
            break;
          case "@": ;
            // Return value
            $result = @current($tuple[$dimension->name()][$register]);
            break;
          case "$": ;
            // Return domain
            $result = @key($tuple[$dimension->name()][$register]);
            break;
        }
        if (!$allow_empty && empty($result)) {
          // Actually this seems to be impossible situation
          throw new YamlElcException("Empty value for dimension '{$dimension->name()}'");
        }
        return $result;
      };
      try {
        $result = preg_replace_callback($regex, $replace_func, $result);
      }
      catch(YamlElcException $e) {
        throw new YamlElcException("String interpolation error for value '$value'\n" . $e->getMessage());
      }
    }
    return $result;
  }

  /**
   * @param $domain_expression
   * @return array
   */
  protected static function parseDomainExpression($domain_expression) {
    if (strpos($domain_expression, ',') !== FALSE) {
      throw new YamlElcException("Initializer key must contain only one domain");
    }
    $parts = array_filter(explode(':', $domain_expression));
    if (count($parts) > 1) {
      throw new YamlElcException("Initializer key must contain only one register");
    }
    return ['R' . key($parts), current($parts)];
  }


  /**
   * @param Dimension[] $dimensions
   * @param array $source_context
   * @return array
   */
  protected function generateDimensionTuples(array $dimensions, $source_context = []) {
    $result = [];
    /** @var Dimension $dimension */
    $dimension = array_shift($dimensions);
    foreach ($dimension->generateTuples($source_context) as $tuple_info) {
      $context = $source_context + [$dimension->name() => $tuple_info];
      if (!empty($dimensions)) {
        $result = array_merge($result, $this->generateDimensionTuples($dimensions, $context));
      }
      else {
        $result[] = $context;
      }
    }
    return $result;
  }

}
