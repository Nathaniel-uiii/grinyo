<?php

function mask_id_number($value)
{
    $value = (string)$value;
    if ($value === '') {
        return '';
    }
    $pos = strpos($value, '-');
    if ($pos === false) {
        return substr($value, 0, 4) . str_repeat('*', max(1, strlen($value) - 5)) . substr($value, -1);
    }
    $head = substr($value, 0, $pos + 1);
    $tail = substr($value, $pos + 1);
    if (strlen($tail) <= 1) {
        return $head . $tail;
    }
    return $head . str_repeat('*', strlen($tail) - 1) . substr($tail, -1);
}

function mask_username($value)
{
    $value = (string)$value;
    $len = strlen($value);
    if ($len === 0) {
        return '';
    }
    if ($len <= 2) {
        return $value;
    }
    return substr($value, 0, 1) . str_repeat('*', $len - 2) . substr($value, -1);
}
