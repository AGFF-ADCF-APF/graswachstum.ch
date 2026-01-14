<?php
namespace Unit;

use Codeception\Test\Unit;

class SEOGeneratorUtilTest extends Unit
{
    protected function callProtectedStatic(string $method, array $args = [])
    {
        $ref = new \ReflectionClass(\Grav\Plugin\SEOMagic\SEOGenerator::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    public function testGetValidLinkVariants()
    {
        $base = 'https://example.com/dir/page';
        $this->assertSame('https://example.com/', $this->callProtectedStatic('getValidLink', ['/', $base]));
        $this->assertSame('https://example.com/dir/page/relative', $this->callProtectedStatic('getValidLink', ['relative', $base]));
        $this->assertSame('https://example.com/dir/page/relative/path', $this->callProtectedStatic('getValidLink', ['relative/path', $base]));
        $this->assertSame('https://other.com/x', $this->callProtectedStatic('getValidLink', ['https://other.com/x', $base]));
    }

    public function testIsExternalDetection()
    {
        $base = 'https://example.com/dir/page';
        $this->assertTrue($this->callProtectedStatic('isExternal', ['https://example.com/another', $base]));
        $this->assertFalse($this->callProtectedStatic('isExternal', ['/local/path', $base]));
        $this->assertFalse($this->callProtectedStatic('isExternal', ['tel:1234', $base]));
        $this->assertTrue($this->callProtectedStatic('isExternal', ['https://other.com/', $base]));
    }

    public function testGetResponseHeadersFiltersAndNormalizes()
    {
        $raw = [
            'HTTP/1.1 200 OK',
            'Date: Tue, 01 Jan 2030 00:00:00 GMT',
            'Server: nginx',
            'Content-Type: text/html; charset=UTF-8',
            'X-Other: nope',
            'Grav-Base: https://example.com/',
        ];

        $filtered = \Grav\Plugin\SEOMagic\SEOGenerator::getResponseHeaders($raw);
        $this->assertArrayHasKey('date', $filtered);
        $this->assertArrayHasKey('server', $filtered);
        $this->assertArrayHasKey('content-type', $filtered);
        $this->assertArrayHasKey('grav-base', $filtered);
        $this->assertArrayNotHasKey('x-other', $filtered);

        $this->assertSame('nginx', $filtered['server']);
        $this->assertSame('text/html; charset=UTF-8', $filtered['content-type']);
        $this->assertSame('https://example.com/', $filtered['grav-base']);
    }
}
