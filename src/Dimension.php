<?php

namespace YamlElc;

class Dimension {

  protected $name;
  protected $items = [];

  protected $brackets;
  protected $left_bracket;
  protected $right_bracket;
  protected $bracket_length;
  /** @var Dimension[] */
  protected $depends;
  protected $conditions;

  public function __construct($name, $brackets, array $items, array $conditions, array $depends) {
    $this->name = $name;
    $this->items = $items;
    $this->conditions = $conditions;
    $this->depends = $depends;
    $this->brackets = preg_split('//u', $brackets, -1, PREG_SPLIT_NO_EMPTY);
  }

  public function resolveValue($value, $register) {
    if (!$this->hasRegister($register)) {
      return FALSE;
    }
    $domain = NULL;
    if (preg_match('~^(.*?)\.(.*)$~', $value, $matches)) {
      $domain = $matches[1];
      $value = $matches[2];
    }
    if (isset($domain) && !$this->hasDomain($domain, $register)) {
      return FALSE;
    }
    if ($this->hasDomain($domain = $value, $register)) {
      // We've got a domain, return ALL its values
      return [$domain => $this->getValues($domain, $register)];
    }
    // Trying to guess first matching value
    foreach ($this->getDomains($register) as $domain) {
      if ($this->hasValue($value, $domain, $register)) {
        return [$domain => [$value]];
      }
    }
    return FALSE;
  }

  protected function applyItemsFilter($filter, &$items, &$conditions) {
    $new_items = [];
    $new_conditions = [];
    foreach ($items as $id => $item) {
      $register = key($item);
      if (key($filter) == $register) {
        // Filter applicable
        $res = Util::hierarchicalIntersect(current($item), current($filter));
        if (!empty($res)) {
          $new_items[] = [$register => $res];
          $new_conditions[] = $conditions[$id];
        }
      }
      else {
        // Copy old values to new ones
        $new_items[] = $item;
        $new_conditions[] = $conditions[$id];
      }
    }
    $items = $new_items;
    $conditions = $new_conditions;
  }

  protected function updateConditionedItems() {
    foreach ($this->conditions as $id => $item_conditions) {
      foreach ($item_conditions as $cid => $condition) {
        $tests = [];
        $dname = key($condition);
        $dim = $this->depends[$dname];
        foreach ($dim->getRegisters() as $register) {
          foreach ($dim->getDomains($register) as $domain) {
            $merged_item = $dim->getMergedItem($domain, $register);
            $res = Util::hierarchicalIntersect($merged_item, $condition[$dname]);
            if (!empty($res)) {
              // Update the condition using its intersection with the merged values of the referenced dimension
              $this->conditions[$id][$cid][$dname] = $res;
              $tests[] = TRUE;
            }
            else {
              $tests[] = FALSE;
            }
          }
        }
        if (!Util::ifAny($tests)) {
          // Found no matches for conditions
          unset($this->items[$id]);
          unset($this->conditions[$id]);
        }
      }
    }
  }

  public function filterItems($filters) {
    // Filter direct items of the dimensions
    foreach ($filters as $filter) {
      if (key($filter) == $this->name) {
        $this->applyItemsFilter(current($filter), $this->items, $this->conditions);
      }
    }
    // Apply new conditions on conditional dimension values
    $this->updateConditionedItems();
  }

  public function generateTuples(array $context) {
    return $this->generateTuplesRecursive($this->items, $this->conditions, $context);
  }

  protected function generateTuplesRecursive($items, $conditions, array $context, $registers = NULL, $source_accum = []) {
    if (is_null($registers)) {
      $registers = $this->getRegisters();
    }
    $register = array_shift($registers);
    $result = [];
    foreach ($this->getItems($register) as $id => $item) {
      $temp_result = [];
      $tests = [];
      foreach ($conditions[$id] as $condition) {
        $res = Util::arrayContains($condition, $context);
        $tests[] = !empty($res);
      }
      if (!Util::ifAll($tests)) {
        continue;
      }
      foreach (current($item) as $domain => $values) {
        foreach ($values as $value) {
          $accum = $source_accum + [$register => [$domain => $value]];
          if (!empty($registers)) {
            $temp_result = array_merge($temp_result, $this->generateTuplesRecursive($items, $conditions, $context, $registers, $accum));
          }
          else {
            $temp_result[] = $accum;
          }
        }
      }
      $result = array_merge($result, $temp_result);
    }
    return $result;
  }

  public function getInfo() {
    $info = [];
    foreach ($this->getItems() as $id => $item) {
      foreach ($item as $register => $domain_info) {
        foreach ($domain_info as $domain => $values) {
          $info[$register][$domain][] = ['values' => $values, 'conditions' => $this->conditions[$id]];
        }
      }
    }
    return $info;
  }

  public function brs() {
    return implode($this->brackets);
  }

  public function brl() {
    if (!isset($this->bracket_length)) {
      $this->bracket_length = count($this->brackets) / 2;
    }
    return $this->bracket_length;
  }

  public function lb($i = NULL) {
    if (!isset($this->left_bracket)) {
      $this->left_bracket = array_slice($this->brackets, 0, $this->brl());
    }
    return isset($i) ? $this->left_bracket[$i] : $this->left_bracket;
  }

  public function rb($i = NULL) {
    if (!isset($this->right_bracket)) {
      $this->right_bracket = array_slice($this->brackets, $this->brl());
    }
    return isset($i) ? $this->right_bracket[$i] : $this->right_bracket;
  }

  public function getRegisters() {
    $registers = [];
    foreach ($this->items as $id => $item) {
      $registers[] = key($item);
    }
    return array_unique($registers);
  }

  public function hasRegister($register) {
    $registers = $this->getRegisters();
    return in_array($register, $registers);
  }

  public function getDomains($register) {
    $domains = [];
    foreach ($this->items as $id => $item) {
      if (key($item) == $register) {
        $domains[] = key(current($item));
      }
    }
    return array_unique($domains);
  }

  public function hasDomain($domain, $register) {
    $domains = $this->getDomains($register);
    return in_array($domain, $domains);
  }

  public function hasValue($value, $domain, $register) {
    $values = $this->getValues($domain, $register);
    return in_array($value, $values);
  }

  public function getValues($domain, $register) {
    $values = [];
    foreach ($this->items as $id => $item) {
      if (key($item) == $register && key(current($item)) == $domain) {
        $values = array_merge($values, current(current($item)));
      }
    }
    return array_unique($values);
  }

  public function getMergedItem($domain, $register) {
    $values = [];
    foreach ($this->items as $id => $item) {
      if (key($item) == $register && key(current($item)) == $domain) {
        $values = array_merge_recursive($values, $item);
      }
    }
    return $values;
  }

  public function getItems($register = NULL) {
    if (is_null($register)) {
      return $this->items;
    }
    $items = array_filter($this->items, function($item) use ($register) {
      return key($item) == $register;
    });
    return $items;
  }

  public function name() {
    return $this->name;
  }

}
