<?php

namespace DataNoodle;

use Dotenv\Dotenv;
use Carbon\Carbon;
use ErrorException;
use Ramsey\Uuid\Uuid;
use Predis\Client as Redis;
use GuzzleHttp\Client as Guzzle;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPProtocolConnectionException;

interface rabbitInterface
{
    public function processSuccess();
}

abstract class Service implements rabbitInterface
{
    const timeFormat = "H:i:s d/m/Y";

    public $channel;

    protected $ssl;
    protected $sql;
    protected $req;
    protected $conn;
    protected $host;
    protected $port;
    protected $user;
    protected $pass;
    protected $name;
    protected $vhost;
    protected $queue;
    protected $redis;
    protected $dotenv;
    protected $dbhost;
    protected $dbuser;
    protected $dbname;
    protected $version;
    protected $peername;
    protected $exchange;
    protected $connected;
    protected $stdlogging;
    protected $routingKey;
    protected $connection;
    protected $errorQueue;
    protected $dbpassword;
    protected $errorExchange;
    protected $errorBindingKey;
    protected $reconnect_timeout;
    protected $serviceLoggingName;

    const INFO = "INFO";
    const DEBUG = "DEBUG";
    const ERROR = "ERROR";
    const WARNING = "WARNING";
    const NOTICE = "NOTICE";
    const ALERT = "ALERT";
    const EMERGENCY = "EMERGENCY";
    const FATAL = "FATAL";
    const CRITICAL = "CRITICAL";

    private $rootdir;

    public function __construct(
        $name
    ) {
        set_error_handler([&$this, 'exception_error_handler']);
        $this->setVersion();
        $this->setRedis();
        $this->getEnvVariables();
        $this->setName($name);
        $this->setSSL();
        $this->setHost();
        $this->setPort();
        $this->setUser();
        $this->setPass();
        $this->setVHost();
        $this->setPeerName();
        $this->setSQL(false);
        $this->setStdLogging();
        $this->setReconnectTimeout();
        $this->setConnected(false);
    }

    private function setSQL($sql)
    {
        $this->sql = $sql;
    }

    private function setRedis()
    {
        $this->redis = new Redis();
    }

    private function setVersion()
    {
        $this->version = self::getVersion();
    }

    private function getEnvVariables()
    {
        $this->rootdir = dirname(__DIR__, 4) . '/';
        try {
            $this->log('Loading environment variables', self::DEBUG);
            $this->dotenv = new Dotenv($this->rootdir);
            $this->dotenv->load();
            if (!isset($_ENV['RMQ_HOST'])) { // checks a variable that should be loaded
                $this->log('Failed loading the environment variables', self::ERROR);
                return $this->getEnvVariables(); // if not set, retry ad infinitum
            }
            $this->log('Successfully loaded the environment variables', self::DEBUG);
            $this->dotenv->required([
                'RMQ_HOST',
                'RMQ_USER',
                'RMQ_PASSWORD',
                'RMQ_VHOST',
            ])->notEmpty();
            $this->dotenv->required('RMQ_SSL')->isBoolean();
            $this->dotenv->required('RMQ_PORT')->isInteger();
            $this->dotenv->required('RMQ_RECONNECT_TIMEOUT')->isInteger();
            $this->dotenv->required('STD_LOGGING')->isBoolean();
            $this->dotenv->load();
            return true;
        } catch (\RuntimeException $e) {
            $this->log($e->getMessage(), self::ERROR);
            exit;
        }
    }

    private function setStdLogging()
    {
        $this->stdlogging = filter_var(getenv('STD_LOGGING'), FILTER_VALIDATE_BOOLEAN);
    }

    public function connect()
    {
        if (!$this->connected) {
            try {
                if ($this->ssl) {
                    $this->connection = new AMQPSSLConnection(
                        $this->host,
                        $this->port,
                        $this->user,
                        $this->pass,
                        $this->vhost,
                        ['verify_peer' => false, 'peer_name' => $this->peername],
                        ['heartbeat' => 30, 'read_write_timeout' => 60]
                    );
                } else {
                    $this->connection = new AMQPStreamConnection(
                        $this->host,
                        $this->port,
                        $this->user,
                        $this->pass,
                        $this->vhost
                    );
                }
                $this->channel = $this->connection->channel();
                $this->setErrorExchange('svc.data');
                $this->setConnected(true);
            } catch (AMQPProtocolConnectionException $e) {
                $this->log($e->getMessage(), self::ERROR);
            } catch (ErrorException $e) {
                $this->log($e->getMessage(), self::CRITICAL);
                exit;
            }
        }
    }

    public function useSQL()
    {
        if (empty($this->dotenv)) {
            $this->getEnvVariables();
        }
        $this->dotenv->required('DB_SSL')->isBoolean();
        $this->setSQL(true);
        $this->dbhost = getenv('DB_HOST');
        $this->dbuser = getenv('DB_USERNAME');
        $this->dbname = getenv('DB_DATABASE');
        $this->dbpassword = getenv('DB_PASSWORD');
        if (filter_var(getenv('DB_SSL'), FILTER_VALIDATE_BOOLEAN)) {
            $this->conn = mysqli_init();
            $this->log('Connecting to DB with SSL');
            mysqli_ssl_set(
                $this->conn,
                $this->rootdir . 'client-key.pem',
                $this->rootdir . 'client-cert.pem',
                $this->rootdir . 'ca.pem',
                null,
                null
            );
        }
        try {
            $this->conn = mysqli_connect($this->dbhost, $this->dbuser, $this->dbpassword, $this->dbname);
            //$this->conn->real_connect($this->dbhost, $this->dbuser, $this->dbpassword, $this->dbname);
        } catch (ErrorException $e) {
            $this->log($e->getMessage(), self::CRITICAL);
            exit;
        }
    }

    public function setUser()
    {
        $this->user = getenv('RMQ_USER');
    }

    public function setPass()
    {
        $this->pass = getenv('RMQ_PASSWORD');
    }

    public function setSSL($ssl = null)
    {
        if (empty($ssl)) {
            $this->ssl = filter_var(getenv('RMQ_SSL'), FILTER_VALIDATE_BOOLEAN);
        } else {
            $this->ssl = $ssl;
        }
    }

    public function setPeerName()
    {
        $peername = getenv('RMQ_PEERNAME');
        if (empty($peername)) {
            $peername = gethostname();
        }
        $this->peername = $peername;
    }

    public function setPort()
    {
        $this->port = getenv('RMQ_PORT');
    }

    public function setHost()
    {
        $this->host = getenv('RMQ_HOST');
    }

    public function setName($name)
    {
        $this->name = $name;

        $this->serviceLoggingName = getenv("LOGGING_NAME") ?: 'svc-' . $this->name;
    }

    public function setVHost()
    {
        $this->vhost = getenv('RMQ_VHOST');
    }

    public function setReconnectTimeout()
    {
        $this->reconnect_timeout = getenv('RMQ_RECONNECT_TIMEOUT');
    }

    protected function setErrorExchange($exchange)
    {
        $this->errorExchange = $exchange;
        $this->channel->exchange_declare(
            $exchange,
            'topic',
            false,
            true,
            false
        );
    }

    public function enableSSL()
    {
        $this->setSSL(true);
    }

    public function disableSSL()
    {
        $this->setSSL(false);
    }

    /**
     * This function defines the queue that the service will listen to.
     * @param $queue
     */
    public function setQueue(
        $queue,
        $passive = false,
        $durable = true,
        $exclusive = false,
        $auto_delete = false,
        $nowait = false
    ) {
        try {
            if (!$this->connected) {
                $this->connect();
            }
            if (empty($this->exchange)) {
                throw new Exception('No Exchange declared. Did you run $service->setExchange($exchange) ?');
            }
            $this->channel->queue_declare($queue, $passive, $durable, $exclusive, $auto_delete, $nowait);
            $this->queue = $queue;
            $this->setErrorQueue($queue);
        } catch (Exception $e) {
            $this->log($e->getMessage(), self::ERROR);
        }
    }

    protected function setErrorQueue($queue)
    {
        $this->errorQueue = $queue . '.error';
        $this->channel->queue_declare($this->errorQueue, false, true, false, false, false);
    }

    public function setExchange($exchange, $type = 'topic', $passive = false, $durable = true, $auto_delete = false)
    {
        if (!$this->connected) {
            $this->connect();
        }
        $this->channel->exchange_declare($exchange, $type, $passive, $durable, $auto_delete);
        $this->exchange = $exchange;
    }

    public function bindQueue($routing_key)
    {
        try {
            $this->channel->queue_bind($this->queue, $this->exchange, $routing_key);
            $this->routingKey = $routing_key;
            $this->setErrorBindingKey('error.' . $routing_key);
        } catch (AMQPProtocolChannelException $e) {
            $this->setConnected(false);
            $this->log($e->getMessage(), self::ERROR, $e);
            exit;
        }
    }

    public function unbindQueue($routing_key)
    {
        try {
            $this->channel->queue_unbind($this->queue, $this->exchange, $routing_key);
        } catch (AMQPProtocolChannelException $e) {
            $this->setConnected(false);
            $this->log($e->getMessage(), self::ERROR, $e);
            exit;
        }
    }

    protected function setErrorBindingKey($routing_key)
    {
        $this->errorBindingKey = $routing_key;
        $this->channel->queue_bind($this->errorQueue, $this->errorExchange, $routing_key);
    }

    public function consume($tag = '', $no_local = false, $no_ack = false, $exclusive = false, $nowait = false)
    {
        $this->channel->basic_consume(
            $this->queue,
            $tag,
            $no_local,
            $no_ack,
            $exclusive,
            $nowait,
            [&$this, 'callback']
        );
        $this->log('Connected');
        $this->log('Waiting for messages');
    }

    public function checkRedisConnection()
    {
        $this->log('Checking redis connection', self::DEBUG);
        if (!$this->redis->isConnected()) {
            $this->log('Redis has been disconnected, attempting to reconnect', self::ERROR);
            $this->redis->connect();
        }
    }

    public function callback($req)
    {
        $this->req = $req;
        // check if we actually have a payload.
        if (!empty($this->req->body)) {
            //Check the redis connection
            $this->checkRedisConnection();
            if (!$this->reschedule()) {
                try {
                    $this->processSuccess();
                } catch (ErrorException | ClientException $e) {
                    $this->log($e, self::ERROR, $e);
                }
            }
        }
        $this->req->delivery_info['channel']->basic_ack(
            $this->req->delivery_info['delivery_tag']
        );
    }

    private function reschedule()
    {
        $messageObject = json_decode($this->req->body);
        if (property_exists($messageObject, 'time')) {
            $this->log('Rescheduling for ' . $messageObject->time);
            $this->createSchedule($messageObject);
            return true;
        } elseif (property_exists($messageObject, '_schedule')) {
            $this->log('Received message from Scheduler');
            return false;
        } else {
            $this->log('Received message');
            return false;
        }
    }

    public function createSchedule($messageObject)
    {
        try {
            $payload = $this->makeReschedulePayload($messageObject);
            $this->sendMessage($payload, 'svc.data', 'scheduler.save');
        } catch (ClientException | ConnectException $e) {
            $this->log($e->getMessage(), self::CRITICAL);
            return false;
        }
    }

    public function setConnected($connected)
    {
        $this->connected = $connected;
    }

    public function getConnectedStatus()
    {
        return $this->connected;
    }

    private function makeReschedulePayload($messageObject)
    {
        $messageBody = (array)$messageObject;
        $time = $messageBody['time'];
        unset($messageBody['time']);
        $payload['time'] = $time;
        if (array_key_exists('routing_key', $messageBody)) {
            $payload['routing_key'] = $messageBody['routing_key'];
            unset($messageBody['routing_key']);
        } else {
            $payload['routing_key'] = $this->routingKey;
        }
        $payload['data'] = $messageBody;
        return $payload;
    }

    public function processFailure($exception)
    {
        try {
            if ($this->connected) {
                $this->sendMessage($exception->getMessage(), $this->errorExchange, $this->errorQueue);
                $this->buildStackDriverError($exception);
                if (!empty($this->req) && $this->req->has('reply_to')) {
                    $this->sendMessage($exception->getMessage(), $this->exchange, $this->req->get('reply_to'));
                }
            }
            $this->rescheduleMessage();
        } catch (OutOfBoundsException $e) {
        }
    }

    public function log($message, $level = self::INFO, $exception = null)
    {
        $time = Carbon::NOW()->tz('UTC');
        $array['stackdriver']['message'] = $message;
        if ($this->stdlogging) {
            if (in_array($level, [self::INFO, self::DEBUG])) {
                $handle = STDOUT;
            } else {
                $handle = STDERR;
            }
            fwrite($handle, $time->format(self::timeFormat) . ' ' . $message . '.' . PHP_EOL);
        }
        if ($this->connected) {
            $this->logToStackDriver($array, $level);
        }
        if (!in_array($level, [self::INFO, self::DEBUG]) && !empty($exception)) {
            $this->processFailure($exception);
        }
    }

    private function logToStackDriver($array, $level = 'INFO')
    {
        $log = 'log.' . strtolower($level) . '.' . $this->serviceLoggingName;
        $array['log'] = $this->serviceLoggingName;
        $array['stackdriver']['serviceContext'] = ['service' => $this->serviceLoggingName, 'version' => $this->version];
        return $this->sendMessage($array, $this->exchange, $log);
    }

    public function sendMessage($array, $exchange, $routingKey)
    {
        if ($this->connected) {
            $msg = new AMQPMessage((string)json_encode($array), array('correlation_id' => Uuid::uuid4()->toString()));
            $this->channel->basic_publish($msg, $exchange, $routingKey);
        } else {
            $array['time'] = Carbon::NOW()->addSeconds($this->reconnect_timeout)->timestamp;
            $array['routing_key'] = $routingKey;
            $this->createSchedule($array);
        }
    }

    private function buildStackDriverError($exception)
    {
        if (method_exists($exception, 'getSeverity')) {
            switch ($exception->getSeverity()) {
                case E_PARSE:
                    $errorLevel = "Error";
                    $stackError = "Parse error";
                    break;
                case E_ERROR:
                case E_CORE_ERROR:
                case E_COMPILE_ERROR:
                case E_USER_ERROR:
                case E_RECOVERABLE_ERROR:
                case E_NOTICE:
                    $errorLevel = "Error";
                    $stackError = "Fatal error";
                    break;
                case E_WARNING:
                case E_CORE_WARNING:
                case E_COMPILE_WARNING:
                case E_USER_WARNING:
                case E_DEPRECATED:
                case E_USER_DEPRECATED:
                    $errorLevel = "Warning";
                    $stackError = "Warning";
                    break;
                default:
                    $stackError = "Notice";
                    $errorLevel = "Notice";
            }
        } else {
            $errorLevel = "Error";
            $stackError = "Fatal error";
        }
        $message = "PHP " . $stackError . ": " . (string)$exception;
        $this->log($message, $errorLevel);
    }

    public function exception_error_handler($errno, $errstr, $errfile, $errline)
    {
        throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
    }

    public static function getVersion()
    {
        $version = trim(exec('git describe --tags --abbrev=0'));
        return $version;
    }

    public function reconnect()
    {
        $this->log('Reconnecting in ' . $this->reconnect_timeout . ' seconds');
        sleep($this->reconnect_timeout);
    }

    public function sleep($sleep)
    {
        $this->log('Sleeping for ' . $sleep . ' seconds');
        sleep($sleep);
    }

    public function checkSQLConnection()
    {
        try {
            mysqli_ping($this->conn);
        } catch (ErrorException | msqli_error_exception $e) {
            $this->log($e->getMessage(), self::CRITICAL, $e);
            $this->log("Mysql disconnected. Trying to reconnect");
            return false;
        }
    }

    public function rescheduleMessage($time = null, $routingKey = null)
    {
        if (!empty($this->req)) {
            if (is_null($time)) {
                $time = Carbon::NOW()->addSeconds($this->reconnect_timeout)->timestamp;
            }
            if (is_null($routingKey)) {
                $routingKey = $this->routingKey;
            }
            $messageBody = json_decode($this->req->body, true);
            $messageBody['time'] = $time;
            $messageBody['routing_key'] = $routingKey;
            $this->req->body = json_encode($messageBody);
            $this->reschedule();
        }
    }

    protected function getMessageRoutingKey()
    {
        $key = null;
        if (!empty($this->req)) {
            $key = $this->req->delivery_info['routing_key'];
        }
        return $key;
    }

    public function runService()
    {
        while (true) {
            try {
                $this->connect();
                if (empty($this->exchange)) {
                    $this->setExchange(getenv('RMQ_EXCHANGE'));
                }
                if (empty($this->queue)) {
                    throw new Exception('No Queue has been set. Did you run $service->setQueue($queue) ?');
                }
                if (empty($this->routingKey)) {
                    throw new Exception('No Binding key been set. Did you run $service->bindQueue($routing_key) ?');
                }
                $this->consume();
                while (count($this->channel->callbacks)) {
                    $this->channel->wait();
                }
            } catch (ErrorException $e) {
                $this->log($e->getMessage(), self::CRITICAL);
                $this->log('Reconnecting in ' . filter_var(
                    getenv('RMQ_RECONNECT_TIMEOUT'),
                    FILTER_VALIDATE_INT
                ) . ' seconds');
                $this->reconnect();
            } catch (AMQPTimeoutException | AMQPIOException | AMQPRuntimeException $e) {
                $this->setConnected(false);
                $this->log('Rabbit Timeout ' . $e->getMessage(), self::CRITICAL);
            } catch (Exception  | AMQPProtocolChannelException $e) {
                $this->setConnected(false);
                $this->log($e->getMessage(), self::CRITICAL, $e);
                exit;
            }
        }
    }
}
