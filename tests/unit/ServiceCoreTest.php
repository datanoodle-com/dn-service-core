<?php

use PHPUnit\Framework\TestCase;

class AbstractService extends DataNoodle\Service
{

    public function processSuccess()
    {
    }

    public function setHost()
    {
        $this->host = 'localhost';
    }

    public function setPort()
    {
        $this->port = '5672';
    }

    public function setUser()
    {
        $this->user = 'guest';
    }

    public function setPass()
    {
        $this->pass = 'guest';
    }

    public function getExchange()
    {
        return $this->exchange;
    }

    public function setExchange($exchange, $topic = 'topic', $passive = false, $durable = true, $auto_delete = false)
    {
        $this->exchange = $exchange;
    }

    public function getQueue()
    {
        return $this->queue;
    }
}

class ServiceCoreTest extends TestCase
{
    private $faker;

    public function __construct(?string $name = null, array $data = [], string $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $this->faker = Faker\Factory::create();
    }

    public function testcreateAbstractClassWithoutName()
    {
        try {
            $test = new AbstractService();
        } catch (ArgumentCountError $e) {
            $this->assertInstanceOf(ArgumentCountError::class, $e);
        }
    }

    public function testCreateAbstractClass()
    {
        $name = $this->faker->company;

        $test = $this->getMockBuilder('AbstractService')->setMethods([
            '__construct',
            'getEnvVariables',
            'runService'
        ])->setConstructorArgs([$name])->disableOriginalConstructor()->getMock();

        $test->method('runService')->withAnyParameters()->willReturn(true);

        $this->assertTrue($test->runService());
    }

    public function testSetExchange()
    {
        $exchange = $this->faker->company;

        $test = $this->getMockBuilder('AbstractService')->setMethods([
            '__construct',
            'getEnvVariables',
        ])->setConstructorArgs([$exchange])->disableOriginalConstructor()->getMock();

        $this->setProtectedProperty($test, 'exchange', $exchange);

        $this->assertEquals($exchange, $test->getExchange());
    }

    public function testQueueBinding()
    {
        $name = $this->faker->company;
        $queue = $this->faker->jobTitle;

        $test = $this->getMockBuilder('AbstractService')->setMethods([
            '__construct',
            'getEnvVariables',
        ])->setConstructorArgs([$name])->disableOriginalConstructor()->getMock();

        $this->setProtectedProperty($test, 'queue', $queue);

        $this->assertEquals($queue, $test->getQueue());
    }

    public function setProtectedProperty($object, $property, $value)
    {
        $reflection = new ReflectionClass($object);
        $reflection_property = $reflection->getProperty($property);
        $reflection_property->setAccessible(true);
        $reflection_property->setValue($object, $value);
    }
}
