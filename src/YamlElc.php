<?php

namespace YamlElc;

use Symfony\Component\Yaml\Yaml;

abstract class YamlElc {

  const DIMENSION_REGEX = '~^(.*?)/(.*)$~';

  /**
   * @param array $data
   * @param Config|NULL $config
   * @return NULL|Config
   */
  public static function parse(array $data, Config $config = NULL) {
    $config = $config ?: new Config();
    $dimensions = static::parseDimensions($data, $config->getDimensions());
    $properties = static::parseProperties($data, $dimensions);
    $config->setDimensions($dimensions);
    $config->setData($properties);
    return $config;
  }

  /**
   * @param $data
   * @param array $dimensions
   * @return array
   */
  protected static function parseDimensions($data, $dimensions = []) {
    foreach ($data as $key => $initializers) {
      // Check for dimension declaration
      if (preg_match(self::DIMENSION_REGEX, $key, $matches)) {
        $dimension = static::createDimension($matches[1], $matches[2], $initializers, $dimensions);
        // Adding new dimension
        $dimensions[$dimension->name()] = $dimension;
        // Sort dimensions by brackets length
        uasort($dimensions, function(Dimension $a, Dimension $b) {
          if ($a->brl() == $b->brl()) {
            return 0;
          }
          return $a->brl() > $b->brl() ? -1 : 1;
        });
      }
    }
    return $dimensions;
  }

  /**
   * @param $name
   * @param $brackets
   * @param $initializers
   * @param Dimension[] $dimensions
   * @return Dimension
   */
  protected static function createDimension($name, $brackets, $initializers, array $dimensions) {
    if (!is_string($brackets) || (mb_strlen($brackets) % 2 != 0) || (mb_strlen($brackets) == 0)) {
      throw new YamlElcException("Dimension definition error: brackets string must be symmetrical ($name:$brackets).");
    }
    // Check brackets uniqueness
    foreach ($dimensions as $dimension) {
      if ($brackets == $dimension->brs()) {
        throw new YamlElcException("Dimension definition error: brackets ambiguous ($name:$brackets).");
      }
    }
    // Parse declarations
    list($items, $conditions) = static::parseDimensionItemRecursive($initializers, $dimensions);
    $depends = [];
    foreach($conditions as $condition_group) {
      $depends = array_merge($depends, array_map(function($v) {
        return key($v);
      }, $condition_group));
    }
    $depends_dimensions = array_intersect_key($dimensions, array_flip(array_unique($depends)));
    $dimension = new Dimension($name, $brackets, $items, $conditions, $depends_dimensions);
    return $dimension;
  }

  /**
   * @param $data
   * @param Dimension[] $dimensions
   * @return array
   */
  protected static function parseDimensionItemRecursive($data, array $dimensions) {
    $items = [];
    $conditions = [];
    $augmented_ids = [];
    foreach ($data as $key => $value) {
      list($domain_expr, $key_conditions) = static::parseKeyExpression($key, $dimensions);
      if (is_array($value) && static::isAssoc($value)) {
        list($sub_items, $sub_conditions) = static::parseDimensionItemRecursive($value, $dimensions);
        foreach ($sub_items as $sub_id => $sub_item) {
          $items[] = $sub_item;
          $conditions[] = static::mergeConditions($key_conditions, $sub_conditions[$sub_id]);
        }
      }
      else if (is_array($value)) {
        $items[] = $value;
        $conditions[] = $key_conditions;
      }
      else {
        // Error, must be array
        throw new YamlElcException("Dimension value must be an array: '$value'");
      }
      if ($domain_expr !== '-') {
        try {
          list($register, $domain) = static::parseDomainExpression($domain_expr);
        }
        catch (YamlElcException $e) {
          throw new YamlElcException($e->getMessage() . "\n(Expression: $domain_expr)");
        }
        // Augment items with register and domain
        foreach ($items as $id => $item) {
          if (!in_array($id, $augmented_ids)) {
            $items[$id] = [$register => [$domain => $item]];
          }
          $augmented_ids[] = $id;
        }
      }
    }
    return [$items, $conditions];
  }

  /**
   * @param $data
   * @param $dimensions
   * @return DataArray|DataMap
   */
  protected static function parseProperties($data, $dimensions) {
    // Filter out dimension definitions
    $data = array_filter($data, function($k) {
      return !preg_match(self::DIMENSION_REGEX, $k);
    }, ARRAY_FILTER_USE_KEY);
    return static::parsePropertiesRecursive($data, $dimensions);
  }

  /**
   * @param $data
   * @param $dimensions
   * @param string $source_reference
   * @return DataArray|DataMap
   */
  protected static function parsePropertiesRecursive($data, $dimensions, $source_reference = "") {
    $result = static::isAssoc($data) ? new DataMap() : new DataArray();
    foreach ($data as $key => $value) {
      $reference = $source_reference . '/' . $key;
      list($name, $conditions) = static::parseKeyExpression($key, $dimensions);
      $name = static::normalizeKey($name);
      if (is_array($value)) {
        $below = static::parsePropertiesRecursive($value, $dimensions, $reference);
        if ($result instanceof DataMap) {
          $result->addItem($name, $below, $conditions);
        }
        else {
          $result->addItem($below, $conditions);
        }
      }
      else {
        if ($result instanceof DataMap) {
          $result->addItem($name, $value, $conditions);
        }
        else {
          $result->addItem($value, $conditions);
        }
      }
    }
    return $result;
  }

  /**
   * @param $filters
   * @param $dimensions
   * @return array
   */
  public static function parseFilters($filters, $dimensions) {
    $conditions = [];
    foreach($filters as $filter_string) {
      if (preg_match('~^(.+?)=(.+)$~', $filter_string, $matches)) {
        $dimension_name = $matches[1];
        $condition_expression = $matches[2];
        // Check if $dimension_name is valid
        if (!isset($dimensions[$dimension_name])) {
          throw new YamlElcException("Filter error: dimension '$dimension_name'' is unknown");
        }
        try {
          $conditions = array_merge($conditions ?? [], static::parseConditionExpression($condition_expression, $dimensions[$dimension_name]));
        }
        catch (YamlElcException $e) {
          throw new YamlElcException("Filter error:" . $e->getMessage() . "\n" . "filter: '$filter_string'");
        }
      }
      else {
        throw new YamlElcException("Filter error: incorrect format, filter: '$filter_string'");
      }
    }
    return $conditions;
  }

  /**
   * @param $key
   * @param Dimension[] $dimensions
   * @return array
   */
  protected static function parseKeyExpression($key, array $dimensions) {
    $conditions = [];
    $chars = preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY);
    $end_of_name_position = count($chars);
    foreach($dimensions as $dimension_name => $d) {
      $start = 0;
      while (true) {
        $i = 0;
        $j = 0;
        $state = NULL;
        $condition_expression = '';
        $end = count($chars) - 1;
        for ($pos = $start; $pos < count($chars); $pos++) {
          $char = $chars[$pos];
          switch ($state) {
            case NULL:
              if ($char === $d->lb($i) &&
                // Look ahead to ensure brackets
                array_slice($chars, $pos, $d->brl()) == $d->lb()) {
                // Start reading left bracket
                $i++;
                $start = $pos;
                $state = 'LB';
              }
              else {
                // Reading key name
                continue;
              }
              break;
            case 'LB':
              if ($i < $d->brl() && $char === $d->lb($i)) {
                // Continue reading left bracket of the key
                $i++;
              }
              // Situation ($i >= $bracket_len OR $char !== $lb[$i]) impossible due to the look ahead (@see above)
              else {
                // End of left bracket, start reading content or right bracket
                if ($char === $d->rb($j) &&
                  // Look ahead to ensure brackets
                  array_slice($chars, $pos, $d->brl()) == $d->rb()) {
                  // Start reading right bracket
                  if ($j == $d->brl() - 1) {
                    $end = $pos;
                    $state = 'EXIT';
                  }
                  else {
                    $state = 'RB';
                  }
                  $j++;
                }
                else {
                  // This is dimension value
                  $condition_expression .= $char;
                  $state = 'CONDITION';
                }
              }
              break;
            case 'CONDITION':
              if ($char === $d->rb($j) &&
                // Look ahead to ensure brackets
                array_slice($chars, $pos, $d->brl()) == $d->rb()) {
                // Start reading right bracket
                if ($j == $d->brl() - 1) {
                  $end = $pos;
                  $state = 'EXIT';
                }
                else {
                  $state = 'RB';
                }
                $j++;
              }
              else {
                $condition_expression .= $char;
              }
              break;
            case 'RB':
              if ($j < $d->brl() && $char === $d->rb($j)) {
                // Continue reading right bracket of the key
                if ($j == $d->brl() - 1) {
                  $end = $pos;
                  $state = 'EXIT';
                }
                $j++;
              }
              else {
                // Finished reading dimension. We need to break to remove
                // discovered part and restart process to look for other
                // parts with the same dimension.
                $end = $pos - 1;
                $state = 'EXIT';
              }
              break;
          }
          if ($state == 'EXIT') {
            break;
          }
        }
        if ($state === NULL) {
          // We didn't find anything, finish parsing this dimension
          break;
        }
        if ($state !== 'EXIT') {
          throw new YamlElcException("Parse error (key='$key' dimension='$dimension_name')");
        }
        // Remove discovered part from the key and proceeding to the next iteration
        array_splice($chars, $start, $end - $start + 1);
        // Update the $end_of_name_position to the minimal possible left-side position
        if ($start < $end_of_name_position) {
          $end_of_name_position = $start;
        }
        // Add condition if it's not empty
        if (trim($condition_expression) !== '') {
          try {
            $conditions = array_merge($conditions ?? [], static::parseConditionExpression($condition_expression, $d));
          }
          catch (YamlElcException $e) {
            throw new YamlElcException($e->getMessage() . "\n" . "key: '$key'");
          }
        }
      }
    }
    $key = implode($chars);
    if ($end_of_name_position < count($chars)) {
      throw new YamlElcException("Dimension definition error: garbage at the end of the key: '$key'.\nOriginal key: $key\nKey dimensions:\n{static::serializeArray($conditions)}");
    }
    return [$key, $conditions];
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
   * @param $condition_expression
   * @param Dimension $dimension
   * @return array
   */
  protected static function parseConditionExpression($condition_expression, Dimension $dimension) {
    $conditions = [];
    // AND-conditions
    if (strpos($condition_expression, ',') !== FALSE) {
      throw new YamlElcException("Condition error: comma not allowed.");
    }
    $parts = array_filter(explode(':', $condition_expression));
    // Add R-prefix to registers
    $parts = array_combine(
      array_map(function($k) {
        return 'R'.$k;
      }, array_keys($parts)),
      $parts);
    $values = [];
    foreach ($parts as $register => $value) {
      if (($resolved_values = $dimension->resolveValue($value, $register)) !== FALSE) {
        $values[$register] = array_merge($values[$register] ?? [], $resolved_values);
      }
      else {
        throw new YamlElcException("Domain or value not found: '$value', register: '$register'");
      }
    }
    foreach ($values as $register => $value) {
      $conditions[] = [$dimension->name() => [$register => $value]];
    }
    return $conditions;
  }

  /**
   * @param array $arr
   * @return bool
   */
  protected static function isAssoc(array $arr) {
    if ([] === $arr) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
  }

  /**
   * @param $c1
   * @param $c2
   * @return array
   */
  protected static function mergeConditions($c1, $c2) {
    return static::arrayUniqueRecursive(array_merge_recursive($c1, $c2));
  }

  /**
   * @param $array
   * @return array
   */
  protected static function arrayUniqueRecursive($array) {
    $result = [];
    $buffer = [];
    foreach($array as $key => $value) {
      if (is_array($value)) {
        $result[$key] = static::arrayUniqueRecursive($value);
      }
      else {
        if (!isset($buffer[$value])) {
          $result[$key] = $value;
          $buffer[$value] = 1;
        }
      }
    }
    return $result;
  }

  /**
   * @param $array
   * @return string
   */
  protected static function serializeArray($array) {
    return Yaml::dump($array);
  }

  protected static function isKeyEmpty($key) {
    $key = trim($key);
    return empty($key) || $key == '-';
  }

  protected static function normalizeKey($key) {
    $key = trim($key);
    return empty($key) || $key == '-' ? NULL : $key;
  }

}
