<?php
declare(strict_types=1);

use PHPUnit\Framework\Constraint\ArraySubset;
use PHPUnit\Framework\TestCase;

function nanotime() {
	return (int)(microtime(true) * 1000000);
}

function hello(EventTracer $et) {
	$et->begin("saying hello");
	printf("hello ");
	usleep(100000);
	$et->end();
}

function greet(EventTracer $et, string $name) {
	$et->complete(nanotime(),200000, "greeting $name");
	printf("$name\n");
	usleep(200000);
}


class EventTracerTest extends TestCase {
	private $tmpfile;

	public static function assertArraySubset($subset, $array, bool $checkForObjectIdentity = false, string $message = ''): void {
		$constraint = new ArraySubset($subset, $checkForObjectIdentity);
		static::assertThat($array, $constraint, $message);
	}

	public function setUp(): void {
		parent::setUp();
		$this->tmpfile = "trace.json";
		if(file_exists($this->tmpfile)) unlink($this->tmpfile);
	}

	protected function tearDown(): void {
		parent::tearDown();
		if(file_exists($this->tmpfile)) unlink($this->tmpfile);
	}

	/*
	 * Test the main logging methods
	 */
	public function testEmpty(): void {
		$et = new EventTracer();
		$this->assertEquals(0, count($et->buffer));
	}

	public function testBeginEnd(): void {
		$et = new EventTracer();
		$et->begin("running program");
		$et->end();
		$this->assertEquals(2, count($et->buffer));
		$this->assertArraySubset(["ph"=>"B", "name"=>"running program"], $et->buffer[0]);
		$this->assertArraySubset(["ph"=>"E"], $et->buffer[1]);
	}

	public function testComplete(): void {
		$et = new EventTracer();
		$et->complete(nanotime(), 1000000, "complete item");
		$this->assertEquals(1, count($et->buffer));
		$this->assertArraySubset(["ph"=>"X", "name"=>"complete item"], $et->buffer[0]);
	}

	public function testNesting(): void {
		$et = new EventTracer();
		$et->begin("running program");
		hello($et);
		greet($et, "world");
		$et->end();
		$this->assertEquals(5, count($et->buffer));
		$this->assertArraySubset(["ph"=>"B", "name"=>"running program"], $et->buffer[0]);
		$this->assertArraySubset(["ph"=>"E"], $et->buffer[4]);
	}

	public function testInstant(): void {
		$et = new EventTracer();
		$et->instant("Test Begins", "p");
		$et->begin("running program");
		$et->end();
		$this->assertEquals(3, count($et->buffer));
		$this->assertArraySubset(["ph"=>"i", "name"=>"Test Begins"], $et->buffer[0]);
	}

	public function testCounter(): void {
		$et = new EventTracer();
		$et->counter("cache", ["hits"=>0, "misses"=>0]);
		usleep(10000);
		$et->counter("cache", ["hits"=>1, "misses"=>0]);
		usleep(10000);
		$et->counter("cache", ["hits"=>1, "misses"=>1]);
		$this->assertEquals(3, count($et->buffer));
		$this->assertArraySubset(["ph"=>"C", "name"=>"cache"], $et->buffer[0]);
	}

	/*
	 * Utility things
	 */
	public function testEndOutstanding(): void {
		$et = new EventTracer();

		$et->begin("a");
		$et->begin("b");
		$et->begin("c");
		$this->assertEquals(3, count($et->buffer));

		$et->clear();
		$this->assertEquals(6, count($et->buffer));

		$et->flush($this->tmpfile);
		$this->assertEquals(0, count($et->buffer));
	}

	public function testStreamingWrites(): void {
		// Write two streams and make sure there's only one beginning
		$et1 = new EventTracer($this->tmpfile);
		$et2 = new EventTracer($this->tmpfile);
		$et1->complete(nanotime(), 1, "item 1");
		$et2->complete(nanotime(), 1, "item 2");

		// finished_data = data, minus trailing comma, plus closing brace
		$data = file_get_contents($this->tmpfile);
		$finished_data = substr($data, 0, strlen($data)-2) . "\n]";

		$buffer = json_decode($finished_data);
		$this->assertEquals(2, count($buffer));
	}

	public function testFlushingWrites(): void {
		// Write two streams and make sure there's only one beginning
		$et1 = new EventTracer();
		$et2 = new EventTracer();
		$et1->complete(nanotime(), 1, "flushed 1");
		$et2->complete(nanotime(), 1, "flushed 2");
		$et1->flush($this->tmpfile);
		$et2->flush($this->tmpfile);

		// finished_data = data, minus trailing comma, plus closing brace
		$data = file_get_contents($this->tmpfile);
		$finished_data = substr($data, 0, strlen($data)-2) . "\n]";

		$buffer = json_decode($finished_data);
		$this->assertEquals(2, count($buffer));
	}
		/*
		$data = file_get_contents('trace.json');
		$this->assertContains("BMARK testBasic running program", $data);
		$this->assertContains("START testBasic running program", $data);
		$this->assertContains("START hello saying hello", $data);
		$this->assertContains("ENDOK hello", $data);
		$this->assertContains("START greet greeting world", $data);
		$this->assertContains("ENDOK greet", $data);
		$this->assertContains("ENDOK testBasic", $data);
		*/
}