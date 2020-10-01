<?php

namespace DataNoodle\Tests\unit;

use stdClass;
use Faker\Factory;
use ReflectionClass;
use ArgumentCountError;
use PHPUnit\Framework\TestCase;

class ServiceCoreTest extends TestCase
{
    private $faker;

    public function __construct(?string $name = null, array $data = [], string $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $this->faker = Factory::create();
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

        $test = $this->getMockBuilder(AbstractService::class)->setMethodsExcept([
            'getExchange'
        ])->setConstructorArgs([$exchange])->disableOriginalConstructor()->getMock();

        $this->setProtectedProperty($test, 'exchange', $exchange);

        $this->assertEquals($exchange, $test->getExchange());
    }

    public function testQueueBinding()
    {
        $name = $this->faker->company;
        $queue = $this->faker->jobTitle;

        $test = $this->getMockBuilder(AbstractService::class)->setMethodsExcept(['getQueue'])
            ->disableOriginalConstructor()->getMock();

        $this->setProtectedProperty($test, 'queue', $queue);

        $this->assertEquals($queue, $test->getQueue());
    }

    public function testSetName()
    {
        $name = $this->faker->company;

        $test = $this->getMockBuilder(AbstractService::class)->setMethodsExcept([
            'getName',
            'setName',
        ])->setConstructorArgs([$name])->disableOriginalConstructor()->getMock();

        $test->setName($name);

        $this->assertEquals($name, $test->getName());
    }

    public function testGetMessageObject()
    {
        $name = $this->faker->company;

        $test = $this->getMockBuilder(AbstractService::class)->setMethodsExcept([
            'getMessageObject'
        ])->setConstructorArgs([$name])->disableOriginalConstructor()->getMock();

        $this->setProtectedProperty($test, 'req', $this->buildMessagePayload());

        $messageObject = $test->getMessageObject(true);

        $this->assertIsArray($messageObject);

        $messageObject = $test->getMessageObject(false);

        $this->assertIsObject($messageObject);
    }

    public function setProtectedProperty($object, $property, $value)
    {
        $reflection = new ReflectionClass($object);
        $reflection_property = $reflection->getProperty($property);
        $reflection_property->setAccessible(true);
        $reflection_property->setValue($object, $value);
    }

    private function buildMessagePayload()
    {
        $object = new StdClass;

        $array['first_name'] = $this->faker->firstName;
        $array['last_name'] = $this->faker->lastName;
        $array['email'] = $this->faker->email;

        $object->body = json_encode($array);

        return $object;
    }
}
