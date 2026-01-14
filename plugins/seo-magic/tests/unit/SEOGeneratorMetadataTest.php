<?php
namespace Unit;

use Codeception\Test\Unit;
use Symfony\Component\DomCrawler\Crawler;

class SEOGeneratorMetadataTest extends Unit
{
    public function testParsesHeadMetaTags()
    {
        $html = <<<HTML
        <html><head>
            <meta name="description" content="My Description" />
            <meta name="keywords" content="one,two,three" />
            <meta property="og:title" content="OG Title" />
            <meta charset="utf-8" />
            <title>Ignored for this test</title>
        </head><body>Body text</body></html>
        HTML;

        $crawler = new Crawler($html);
        $metadata = \Grav\Plugin\SEOMagic\SEOGenerator::getMetadata($crawler, 'Body text');

        $this->assertSame('My Description', $metadata['description'] ?? null);
        $this->assertSame('one,two,three', $metadata['keywords'] ?? null);
        $this->assertSame('OG Title', $metadata['og:title'] ?? null);
        $this->assertSame('utf-8', $metadata['charset'] ?? null);
    }
}

