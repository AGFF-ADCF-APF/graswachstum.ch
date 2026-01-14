<?php
namespace Unit;

use Codeception\Test\Unit;
use Grav\Common\Data\Data;
use Grav\Plugin\SEOMagic\SEOScore;
use ReflectionClass;

class SEOScoreTest extends Unit
{
    protected function makeSEOScoreWithData(array $seed): SEOScore
    {
        $data = new Data($seed);

        // Instantiate without running constructor to avoid Grav dependency.
        $ref = new ReflectionClass(SEOScore::class);
        /** @var SEOScore $score */
        $score = $ref->newInstanceWithoutConstructor();

        // Inject required props
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $prop->setValue($score, $data);

        $scoresProp = $ref->getProperty('scores');
        $scoresProp->setAccessible(true);
        $scoresProp->setValue($score, new Data());

        $langProp = $ref->getProperty('lang');
        $langProp->setAccessible(true);
        $langProp->setValue($score, new class {
            public function translate($key) { return $key; }
        });

        return $score;
    }

    public function testTitleLengthInRangeScores100()
    {
        $score = $this->makeSEOScoreWithData([
            'head' => [
                'title' => 'Valid Title', // length 11, between 7 and 60
                'meta' => [
                    'title' => 'Some Meta Title',
                    'description' => str_repeat('a', 100),
                ],
            ],
        ]);

        $scores = $score->getScores();
        $title = $scores->get('items.head.items.title');
        $this->assertIsArray($title);
        $this->assertEquals(100, $title['score']);
    }

    public function testTitleTooShortIsPenalized()
    {
        $score = $this->makeSEOScoreWithData([
            'head' => [
                'title' => 'Short', // length 5; min is 7
            ],
        ]);

        $scores = $score->getScores();
        $title = $scores->get('items.head.items.title');
        $this->assertIsArray($title);
        $this->assertEquals(71, $title['score']); // 5 * 100 / 7 = 71.4 => 71
    }

    public function testDescriptionTooLongDecreasesScore()
    {
        $long = str_repeat('b', 200); // > max(160); uses custom max_factor 0.15 and offset 123
        $score = $this->makeSEOScoreWithData([
            'head' => [
                'title' => 'Valid Title',
                'meta' => [ 'description' => $long ],
            ],
        ]);

        $scores = $score->getScores();
        $desc = $scores->get('items.head.items.meta.items.description');
        $this->assertIsArray($desc);
        $this->assertEquals(93, $desc['score']); // -(0.15*200) + 123 = 93
    }
}

