<?php

namespace YamlElc;

abstract class Util {

  public static function arrayContains($a, $b) {
    foreach ($a as $ak => $av) {
      if (!array_key_exists($ak, $b)) {
        return FALSE;
      }
      if (is_array($av)) {
        if (static::isAssoc($av)) {
          if (is_array($b[$ak]) && static::isAssoc($b[$ak])) {
            return static::arrayContains($av, $b[$ak]);
          }
          else {
            return FALSE;
          }
        }
        else if (is_array($b[$ak]) && !static::isAssoc($b[$ak])) {
          return (bool) array_intersect($av, $b[$ak]);
        }
        else if (!is_array($b[$ak])) {
          return in_array($b[$ak], $av);
        }
        else {
          return FALSE;
        }
      }
      else if (!is_array($b[$ak])) {
        return $av === $b[$ak];
      }
    }
    return TRUE;
  }

  public static function hierarchicalIntersect(array $a, array $b) {
    $result = [];
    foreach ($a as $k => $v) {
      if (is_array($v)) {
        if (static::isAssoc($v)) {
          if (array_key_exists($k, $b) && is_array($b[$k]) && static::isAssoc($b[$k])) {
            if ($res = static::hierarchicalIntersect($v, $b[$k])) {
              $result[$k] = $res;
            }
          }
        }
        else {
          if (array_key_exists($k, $b) && is_array($b[$k]) && !static::isAssoc($b[$k])) {
            if ($res = array_values(array_intersect($v, $b[$k]))) {
              $result[$k] = $res;
            }
          }
        }
      }
      else {
        if (array_key_exists($k, $b) && !is_array($b[$k]) && $b[$k] === $v) {
          $result[$k] = $v;
        }
      }
    }
    return $result;
  }

  public static function ifAll($array) {
    $result = TRUE;
    foreach($array as $item) {
      $result = $result && (bool) $item;
    }
    return $result;
  }

  public static function ifAny($array) {
    $result = FALSE;
    foreach($array as $item) {
      $result = $result || (bool) $item;
    }
    return $result;
  }

  public static function isAssoc(array $arr) {
    if ([] === $arr) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
  }


}
