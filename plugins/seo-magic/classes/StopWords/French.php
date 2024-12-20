<?php

namespace Grav\Plugin\SEOMagic\StopWords;

class French extends \PhpScience\TextRank\Tool\StopWords\French
{
    public function __construct($words = null)
    {
        if (!is_null($words)) {
            $this->words = array_merge($this->words, $words);
        }
    }
}