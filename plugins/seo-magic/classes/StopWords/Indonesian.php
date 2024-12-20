<?php

namespace Grav\Plugin\SEOMagic\StopWords;

class Indonesian extends \PhpScience\TextRank\Tool\StopWords\Indonesian
{
    public function __construct($words = null)
    {
        if (!is_null($words)) {
            $this->words = array_merge($this->words, $words);
        }
    }
}