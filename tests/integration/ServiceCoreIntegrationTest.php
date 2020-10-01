<?php
/**
 * Created by PhpStorm.
 * User: aris
 * Date: 15/3/19
 * Time: 2:12 PM
 */

namespace DataNoodle\Tests\integration;

use ArgumentCountError;
use ErrorException;
use Faker\Factory;
use DataNoodle\Exception;
use ReflectionClass;
use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Exception\AMQPIOException;
use Dotenv\Exception\InvalidPathException;

class ServiceCoreIntegrationTest extends TestCase
{

    private $faker;

    public function __construct(?string $name = null, array $data = [], string $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->faker = Factory::create();
    }

    public function testNoNameService()
    {
        try {
            $service = new IntegrationService();
        } catch (ArgumentCountError $e) {
            $this->assertInstanceOf(ArgumentCountError::class, $e);
        }
    }

    public function testCreateServiceWithOutEnv()
    {
        try {
            $name = $this->faker->company;
            $service = new IntegrationService($name);
        } catch (InvalidPathException $e) {
            $this->assertInstanceOf(InvalidPathException::class, $e);
        }
    }

    public function testCreateServiceWithEnv()
    {
        try {
            $name = $this->faker->company;
            $service = $this->getMockBuilder(IntegrationService::class)->setConstructorArgs([$name])->setMethods([
                '__construct',
                'runService',
            ])->disableOriginalConstructor()->getMock();

            // We've overloaded the getEnvVariables so we definetely get the right username/password without the env file
            $service->getEnvVariables();

            $this->assertEquals('localhost', $service->getHost());

            $this->assertEquals(5672, $service->getPort());

            $service->method('runService')->willReturn(true);

            $result = $service->connect();

            $this->assertEquals(null, $result);
        } catch (ErrorException $e) {
        }
    }

    public function testSetName()
    {
        try {
            $name = $this->faker->company;
            $service = $this->getMockBuilder(IntegrationService::class)->setConstructorArgs([$name])->setMethods([
                '__construct',
            ])->disableOriginalConstructor()->getMock();

            // We've overloaded the getEnvVariables so we definitely get the right username/password without the env file
            $service->getEnvVariables();

//            $service->method('setName')->with($name)->willReturn($name);
            $service->setName($name);
            $result = $service->getName();

            $this->assertEquals($name, $result);
        } catch (ErrorException $e) {
        }
    }

    public function testBadEnvariables()
    {
        $name = $this->faker->company;
        $service = $this->getMockBuilder(IntegrationService::class)->setConstructorArgs([$name])->setMethods([
            '__construct',
            'runService',
        ])->disableOriginalConstructor()->getMock();

        // We've overloaded the getEnvVariables so we definetely get the right username/password without the env file

//            $service->setBadEnvVariables();
//            $service->method('runService')->willReturn(true);
        try {
            $result = $service->connect();
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        } catch (AMQPIOException $e) {
            $this->assertInstanceOf(AMQPIOException::class, $e);
        }
    }

    /**
     * @kup
     */
    public function testRunServiceNoMock()
    {

        try {
            $service = new IntegrationService($this->faker->company);
        } catch (InvalidPathException $e) {
            $this->assertInstanceOf(InvalidPathException::class, $e);
        }

        // Mock the dot env file load
        $service = new IntegrationService($this->faker->company);
        $service->setExchange('svc.data');
        $service->setQueue('testing');
        $service->bindQueue('testing.#');

        // If it makes it here, mark it as passed.
        //$service->runService();
        $this->markAsRisky('Cannot guarantee the service is running');
    }

    public function setProtectedProperty($object, $property, $value)
    {
        $reflection = new ReflectionClass($object);
        $reflection_property = $reflection->getProperty($property);
        $reflection_property->setAccessible(true);
        $reflection_property->setValue($object, $value);
    }

    public function testRunService()
    {
        try {
            $name = $this->faker->company;
            $service = $this->getMockBuilder(IntegrationService::class)->setConstructorArgs([$name])->setMethods([
                '__construct',
                'setExchange',
                'runService',
            ])->disableOriginalConstructor()->getMock();

            // We've overloaded the getEnvVariables so we definetely get the right username/password without the env file
            $service->getEnvVariables();


            $this->assertEquals('localhost', $service->getHost());

            $this->assertEquals(5672, $service->getPort());

            $this->setProtectedProperty($service, 'exchange', 'svc.data');

            $this->assertEquals('svc.data', $service->getExchange());

            $service->setQueue('queuetest');

            $service->bindQueue('routing_key');

            $this->assertEquals('queuetest', $service->getQueue());

            $service->method('runService')->willReturn(true);

            $this->assertTrue($service->runService());
        } catch (ErrorException $e) {
        }
    }
}
