<?php

namespace Grav\Plugin\SEOMagic\StopWords;

class Norwegian extends \PhpScience\TextRank\Tool\StopWords\Norwegian
{
    public function __construct($words = null)
    {
        if (!is_null($words)) {
            $this->words = array_merge($this->words, $words);
        }
    }
}