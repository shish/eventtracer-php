<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

function nanotime(): int
{
    return (int)(microtime(true) * 1000000);
}

$out = "";
function hello(EventTracer $et): void
{
    global $out;
    $et->begin("saying hello");
    $out .= "hello ";
    usleep(100000);
    $et->end();
}

function greet(EventTracer $et, string $name): void
{
    global $out;
    $et->complete(nanotime(), 200000, "greeting $name");
    $out .= "$name\n";
    usleep(200000);
}


class EventTracerTest extends TestCase
{
    private string $tmpfile;

    /**
     * @param array<string, mixed> $subset
     * @param array<string, mixed> $array
     * @param bool $checkForObjectIdentity
     * @param string $message
     */
    public static function assertArraySubset(array $subset, array $array, bool $checkForObjectIdentity = false, string $message = ''): void
    {
        foreach ($subset as $key => $value) {
            static::assertEquals($value, $array[$key]);
        }
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->tmpfile = "trace.json";
        if (file_exists($this->tmpfile)) {
            unlink($this->tmpfile);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->tmpfile)) {
            unlink($this->tmpfile);
        }
    }

    /*
     * Test the main logging methods
     */
    public function testEmpty(): void
    {
        $et = new EventTracer();
        $this->assertEquals(0, count($et->buffer));
    }

    public function testBeginEnd(): void
    {
        $et = new EventTracer();
        $et->begin("running program");
        $et->end();
        $this->assertEquals(2, count($et->buffer));
        $this->assertArraySubset(["ph" => "B", "name" => "running program"], $et->buffer[0]);
        $this->assertArraySubset(["ph" => "E"], $et->buffer[1]);
    }

    public function testComplete(): void
    {
        $et = new EventTracer();
        $et->complete(nanotime(), 1000000, "complete item");
        $this->assertEquals(1, count($et->buffer));
        $this->assertArraySubset(["ph" => "X", "name" => "complete item"], $et->buffer[0]);
    }

    public function testNesting(): void
    {
        $et = new EventTracer();
        $et->begin("running program");
        hello($et);
        greet($et, "world");
        $et->end();
        $this->assertEquals(5, count($et->buffer));
        $this->assertArraySubset(["ph" => "B", "name" => "running program"], $et->buffer[0]);
        $this->assertArraySubset(["ph" => "E"], $et->buffer[4]);
    }

    public function testInstant(): void
    {
        $et = new EventTracer();
        $et->instant("Test Begins", "p");
        $et->begin("running program");
        $et->end();
        $this->assertEquals(3, count($et->buffer));
        $this->assertArraySubset(["ph" => "I", "name" => "Test Begins"], $et->buffer[0]);
    }

    public function testCounter(): void
    {
        $et = new EventTracer();
        $et->counter("cache", ["hits" => 0, "misses" => 0]);
        usleep(10000);
        $et->counter("cache", ["hits" => 1, "misses" => 0]);
        usleep(10000);
        $et->counter("cache", ["hits" => 1, "misses" => 1]);
        $this->assertEquals(3, count($et->buffer));
        $this->assertArraySubset(["ph" => "C", "name" => "cache"], $et->buffer[0]);
    }

    public function testAsync(): void
    {
        $et = new EventTracer();
        $et->async_start("start", "my_id");
        usleep(10000);
        $et->async_instant("instant", "my_id");
        usleep(10000);
        $et->async_end("end", "my_id");
        $this->assertEquals(3, count($et->buffer));
        $this->assertArraySubset(["ph" => "b", "name" => "start"], $et->buffer[0]);
    }

    public function testFlow(): void
    {
        $et = new EventTracer();
        $et->flow_start("start", "my_id");
        usleep(10000);
        $et->flow_instant("instant", "my_id");
        usleep(10000);
        $et->flow_end("end", "my_id");
        $this->assertEquals(3, count($et->buffer));
        $this->assertArraySubset(["ph" => "s", "name" => "start"], $et->buffer[0]);
    }

    public function testObject(): void
    {
        $et = new EventTracer();
        $et->object_created("my_ob", "my_id");
        usleep(10000);
        $et->object_snapshot("my_ob", "my_id");
        usleep(10000);
        $et->object_destroyed("my_ob", "my_id");
        $this->assertEquals(3, count($et->buffer));
        $this->assertArraySubset(["ph" => "N", "name" => "my_ob"], $et->buffer[0]);
    }

    public function testMetadata(): void
    {
        $et = new EventTracer();
        $et->metadata("process_name", ["name" => "my_process_name"]);
        $et->metadata("process_labels", ["labels" => "my_process_label"]);
        $et->metadata("process_sort_index", ["sort_index" => 0]);
        $et->metadata("thread_name", ["name" => "my_thread_name"]);
        $et->metadata("thread_sort_index", ["sort_index" => 0]);
        $this->assertEquals(5, count($et->buffer));
        $this->assertArraySubset(["ph" => "M", "name" => "process_name"], $et->buffer[0]);
    }

    public function testMark(): void
    {
        $et = new EventTracer();
        $et->mark("my_mark");
        $this->assertEquals(1, count($et->buffer));
        $this->assertArraySubset(["ph" => "R", "name" => "my_mark"], $et->buffer[0]);
    }

    public function testClockSync(): void
    {
        $et = new EventTracer();
        $et->clock_sync("sync", "sync_id", null);
        $et->clock_sync("sync", "sync_id", 12345);
        $this->assertEquals(2, count($et->buffer));
        $this->assertArraySubset(["ph" => "c", "name" => "sync"], $et->buffer[0]);
    }

    public function testContext(): void
    {
        $et = new EventTracer();
        $et->context_enter("context", "context_id");
        $et->context_leave("context", "context_id");
        $this->assertEquals(2, count($et->buffer));
        $this->assertArraySubset(["ph" => "(", "name" => "context"], $et->buffer[0]);
    }

    /*
     * I/O error situations
     */
    public function testFlushUnbuffered(): void
    {
        $this->expectException(\Exception::class);
        $et = new EventTracer($this->tmpfile);
        $et->flush($this->tmpfile);
    }

    /*
     * Utility things
     */
    public function testEndOutstanding(): void
    {
        $et = new EventTracer();
        $et->clear(); // clearing an un-used thread shouldn't crash

        $et->begin("a");
        $et->begin("b");
        $et->begin("c");
        $this->assertEquals(3, count($et->buffer));

        $et->clear();
        $this->assertEquals(6, count($et->buffer));

        $et->flush($this->tmpfile);
        $this->assertEquals(0, count($et->buffer));
    }

    public function testStreamingWrites(): void
    {
        // Write two streams and make sure there's only one beginning
        $et1 = new EventTracer($this->tmpfile);
        $et2 = new EventTracer($this->tmpfile);
        $et1->complete(nanotime(), 1, "item 1");
        $et2->complete(nanotime(), 1, "item 2");

        // finished_data = data, minus trailing comma, plus closing brace
        $data = file_get_contents($this->tmpfile);
        assert($data !== false);
        $finished_data = substr($data, 0, strlen($data) - 2) . "\n]";

        /** @var array<int, array<string, mixed>> $buffer */
        $buffer = json_decode($finished_data, true);
        $this->assertEquals(2, count($buffer));
    }

    public function testFlushingWrites(): void
    {
        // Write two streams and make sure there's only one beginning
        $et1 = new EventTracer();
        $et2 = new EventTracer();
        $et1->complete(nanotime(), 1, "flushed 1");
        $et2->complete(nanotime(), 1, "flushed 2");
        $et1->flush($this->tmpfile);
        $et2->flush($this->tmpfile);

        // finished_data = data, minus trailing comma, plus closing brace
        $data = file_get_contents($this->tmpfile);
        assert($data !== false);
        $finished_data = substr($data, 0, strlen($data) - 2) . "\n]";

        /** @var array<int, array<string, mixed>> $buffer */
        $buffer = json_decode($finished_data, true);
        $this->assertEquals(2, count($buffer));
    }

    /**
     * Test raw overrides
     */
    public function testOverrideTS(): void
    {
        // We can manually create an event which ends before it begins.
        // We shouldn't... but we can
        $et = new EventTracer();
        $et->begin("raw", raw: ["ts" => 100]);
        $et->end(raw: ["ts" => 0]);
        $this->assertEquals($et->buffer[0]["ts"], 100);
        $this->assertEquals($et->buffer[1]["ts"], 0);
        $et->flush($this->tmpfile);

        $data = file_get_contents($this->tmpfile);
        assert($data !== false);
        $finished_data = substr($data, 0, strlen($data) - 2) . "\n]";

        /** @var array<int, array<string, mixed>> $buffer */
        $buffer = json_decode($finished_data, true);
        $this->assertTrue($buffer[0]["ts"] > $buffer[1]["ts"]);
    }

}
