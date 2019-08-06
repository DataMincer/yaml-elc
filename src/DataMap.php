<?php

namespace YamlElc;

use Iterator;

class DataMap implements Iterator {

  protected $keys = [];
  protected $values = [];

  protected $conditions = [];
  protected $reference;

  private $position = 0;

  public function __construct() {
    $this->position = 0;
  }

  public function current() {
    return [$this->keys[$this->position], $this->values[$this->position], $this->conditions[$this->position]];
  }

  public function next() {
    ++$this->position;
  }

  public function key() {
    return $this->position;
  }

  public function valid() {
    return array_key_exists($this->position, $this->keys);
  }

  public function rewind() {
    $this->position = 0;
  }

  public function addItem($name, $value, $conditions) {
    $this->keys[] = $name;
    $this->values[] = $value;
    $this->conditions[] = $conditions;
  }

  public function filterItems() {

  }


}
