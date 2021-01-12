<?php

namespace DataNoodle;

use PDO;
use Dotenv\Dotenv;
use Carbon\Carbon;
use ErrorException;
use PhpAmqpLib\Exception\AMQPBasicCancelException;
use Ramsey\Uuid\Uuid;
use GuzzleHttp\Client as Guzzle;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Dotenv\Exception\InvalidPathException;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPProtocolConnectionException;

abstract class Service implements RabbitInterface
{
    const TIMEFORMAT = "H:i:s d/m/Y";

    /**
     * @var AMQPChannel
     */
    public $channel;

    /**
     * @var Guzzle
     */
    protected $api;

    /**
     * @var bool
     */
    protected $ssl;

    /**
     * @var bool
     */
    protected $sql;

    /**
     * @var AMQPMessage
     */
    protected $req;

    /**
     * @var PDO
     */
    protected $conn;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var string
     */
    protected $user;

    /**
     * @var string
     */
    protected $pass;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $vhost;

    /**
     * @var string
     */
    protected $queue;

    /**
     * @var Dotenv
     */
    protected $dotenv;

    /**
     * @var string
     */
    protected $dbhost;

    /**
     * @var int
     */
    protected $dbport;

    /**
     * @var string
     */
    protected $dbuser;

    /**
     * @var string
     */
    protected $dbname;

    /**
     * @var string
     */
    protected $version;

    /**
     * @var string
     */
    protected $peername;

    /**
     * @var string
     */
    protected $exchange;

    /**
     * @var bool
     */
    protected $connected;

    /**
     * @var bool
     */
    protected $scheduler;

    /**
     * @var bool
     */
    protected $stdlogging;

    /**
     * @var string
     */
    protected $routingKey;

    /**
     * @var AMQPSSLConnection|AMQPStreamConnection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $errorQueue;

    /**
     * @var string
     */
    protected $dbpassword;

    /**
     * @var string
     */
    protected $errorExchange;

    /**
     * @var string
     */
    protected $errorBindingKey;

    /**
     * @var int
     */
    protected $reconnect_timeout;

    /**
     * @var string
     */
    protected $serviceLoggingName;

    /**
     * @var bool
     */
    protected $schedulerConnected;

    /**
     * @var int
     */
    protected $prefetch = 0;

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
        set_error_handler([&$this, 'exceptionErrorHandler']);
        $this->setVersion();
        $this->getEnvVariables();
        $this->setName($name);
        $this->setSSL();
        $this->setHost();
        $this->setPort();
        $this->setUser();
        $this->setPass();
        $this->setVHost();
        $this->setPeerName();
        $this->setPrefetch();
        $this->setSQL(false);
        $this->setStdLogging();
        $this->setApiClient();
        $this->setReconnectTimeout();
        $this->setConnected(false);
    }

    private function setSQL(bool $sql): void
    {
        $this->sql = $sql;
    }

    private function setVersion(): void
    {
        $this->version = self::getVersion();
    }

    public function setRootDir($dir)
    {
        $this->rootdir = $dir;
    }


    private function getEnvVariables()
    {

        $this->rootdir = dirname(__DIR__, 4) . '/';

        if (!file_exists($this->rootdir . '.env')) {
            $this->rootdir = dirname(__DIR__) . '/';
        }
        try {
            $this->log('Loading environment variables', self::DEBUG);
            $this->dotenv = Dotenv::createMutable($this->rootdir);
            try {
                $this->dotenv->load();
            } catch (InvalidPathException $e) {
                $this->log('Couldn\'t find the env, check for the current directory', self::ERROR);
                $this->rootdir = dirname(__DIR__) . '/';
                $this->dotenv = new Dotenv($this->rootdir);
                $this->dotenv->load();
                $this->log('loaded the env file locally');
            }

            $this->log('Successfully loaded the environment variables', self::DEBUG);
            $this->dotenv->required([
                'RMQ_HOST',
                'RMQ_USER',
                'RMQ_PASSWORD',
                'RMQ_VHOST',
                'SCHEDULER_HOST'
            ])->notEmpty();

            if (!empty($_ENV['API_HOST'])) {
                $this->dotenv->required([
                    'API_CLIENT_ID',
                    'API_CLIENT_SECRET'
                ])->notEmpty();
            }

            if (!empty($_ENV['RMQ_PREFETCH'])) {
                $this->dotenv->required('RMQ_PREFETCH')->isInteger();
            }

            $this->dotenv->required('RMQ_SSL')->isBoolean();
            $this->dotenv->required('RMQ_PORT')->isInteger();
            $this->dotenv->required('RMQ_RECONNECT_TIMEOUT')->isInteger();
            $this->dotenv->required('STD_LOGGING')->isBoolean();
            $this->dotenv->load();
            $this->setScheduler();
            return true;
        } catch (\RuntimeException $e) {
            $this->log($e->getMessage(), self::ERROR);
            exit;
        }
    }

    private function setScheduler()
    {
        if (filter_var($_ENV['SCHEDULER_HOST'], FILTER_VALIDATE_URL)) {
            $this->guzzle = new Guzzle(['base_uri' => $_ENV['SCHEDULER_HOST']]);
            $this->scheduler = true;
            $this->schedulerConnected = true;
        } else {
            $this->scheduler = false;
            $this->schedulerConnected = false;
        }
    }

    private function setApiClient()
    {
        if (!empty($_ENV['API_HOST'])) {
            $this->api = new Guzzle([
                'base_uri' => $_ENV['API_HOST'],
                'headers' => ['client-id' => $_ENV['API_CLIENT_ID'], 'client-secret' => $_ENV['API_CLIENT_SECRET']]
            ]);
        }
    }

    private function setStdLogging()
    {
        $this->stdlogging = filter_var($_ENV['STD_LOGGING'], FILTER_VALIDATE_BOOLEAN);
    }

    public function connect()
    {
        if (!$this->connected) {
            try {
                if ($this->ssl) {
                    AMQPSSLConnection::$LIBRARY_PROPERTIES['connection_name'] = ['S', $this->name];
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
                    AMQPStreamConnection::$LIBRARY_PROPERTIES['connection_name'] = ['S', $this->name];
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
                throw new Exception($e->getMessage());
            }
        }
    }

    public function useSQL()
    {
        try {
            $this->log('Connecting to Cockroach');
            $this->conn = new PDO($this->buildPDOString(), null, null, [
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $this->log('Success: Cockroach DB Connected');
        } catch (\PDOException $e) {
            $this->log($e->getMessage(), self::ERROR, $e);
        }
    }

    private function buildPDOString()
    {
        if (empty($this->dotenv)) {
            $this->getEnvVariables();
        }

        $this->dotenv->required('DB_SSL')->isBoolean();
        $this->dotenv->required('DB_PORT')->isInteger();
        $this->setSQL(true);
        $this->dbhost = $_ENV['DB_HOST'];
        $this->dbport = $_ENV['DB_PORT'];
        $this->dbuser = $_ENV['DB_USERNAME'];
        $this->dbname = $_ENV['DB_DATABASE'];
        $this->dbpassword = $_ENV['DB_PASSWORD'];
        $string = 'pgsql:host=' . $this->dbhost . ';port=' . $this->dbport . ';dbname=' . $this->dbname . ';user=' . $this->dbuser . ';password=' . $this->dbpassword . ';sslmode=';

        if (filter_var($_ENV['DB_SSL'], FILTER_VALIDATE_BOOLEAN)) {
            $sslmode = 'verify-full';
            $sslString = $sslmode . ';sslrootcert=' . $_ENV['DB_SSL_ROOTCERT'] . ';sslkey=' . $_ENV['DB_SSL_KEY'] . ';sslcert=' . $_ENV['DB_SSL_CERT'];
        } else {
            $sslmode = 'disable';
            $sslString = 'disable';
        }
        return $string . $sslString;
    }

    public function setUser()
    {
        $this->user = $_ENV['RMQ_USER'];
    }

    public function setPass()
    {
        $this->pass = $_ENV['RMQ_PASSWORD'];
    }

    /**
     * Sets the class prefetch value
     */
    public function setPrefetch(): void
    {
        if ($_ENV['RMQ_PREFETCH'] > 0) {
            $this->prefetch = $_ENV['RMQ_PREFETCH'];
        }
    }

    public function setSSL($ssl = null)
    {
        if (empty($ssl)) {
            $this->ssl = filter_var($_ENV['RMQ_SSL'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $this->ssl = $ssl;
        }
    }

    public function setPeerName()
    {
        $peername = $_ENV['RMQ_PEERNAME'];
        if (empty($peername)) {
            $peername = gethostname();
        }
        $this->peername = $peername;
    }

    public function setPort()
    {
        $this->port = $_ENV['RMQ_PORT'];
    }

    public function setHost()
    {
        $this->host = $_ENV['RMQ_HOST'];
    }

    public function setName($name)
    {
        $this->name = $name;

        $this->serviceLoggingName = $_ENV["LOGGING_NAME"] ?: 'svc-' . $this->name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setVHost()
    {
        $this->vhost = $_ENV['RMQ_VHOST'];
    }

    public function setReconnectTimeout()
    {
        $this->reconnect_timeout = $_ENV['RMQ_RECONNECT_TIMEOUT'];
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

    /**
     * This function handles the consuming of the queue
     *
     * @param string $tag
     * @param bool $no_local
     * @param bool $no_ack
     * @param bool $exclusive
     * @param bool $nowait
     */
    public function consume(
        string $tag = '',
        bool $no_local = false,
        bool $no_ack = false,
        bool $exclusive = false,
        bool $nowait = false
    ) {

        if ($this->prefetch > 0) {
            $this->channel->basic_qos(null, $this->prefetch, null);
        }

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

    public function callback($req)
    {
        $this->req = $req;
        // check if we actually have a payload.
        if (!empty($this->req->body)) {
            if (!$this->reschedule()) {
                try {
                    $this->processSuccess();
                } catch (ErrorException | ClientException $e) {
                    $this->log($e, self::ERROR, $e);
                }
            }
        }
        $this->req->getChannel()->basic_ack($this->req->getDeliveryTag());
    }

    private function reschedule()
    {
        $messageObject = $this->getMessageObject(false);
        if ($messageObject !== null) {
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
        return false;
    }

    public function createSchedule($messageObject)
    {
        try {
            $payload = $this->makeReschedulePayload($messageObject);
            $response = $this->guzzle->request('POST', '/', ['json' => $payload]);
            $contents = json_decode($response->getBody()->getContents());
            $messageBody['schedule_id'] = $contents->result->uuid;
            $this->schedulerConnected = true;
            return $contents->result->uuid;
        } catch (ClientException | ConnectException | RequestException $e) {
            $this->schedulerConnected = false;
            $this->log($e->getMessage(), self::CRITICAL);
            return false;
        }
    }

    public function updateSchedule($messageObject)
    {
        $payload = $this->makeReschedulePayload($messageObject);
        $this->guzzle->request('PUT', '/' . $messageObject->schedule_id, ['json' => $payload]);
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
            if ($this->schedulerConnected) {
                $this->rescheduleMessage();
            }
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
            fwrite($handle, $time->format(self::TIMEFORMAT) . ' ' . $message . '.' . PHP_EOL);
        }
        $this->logToStackDriver($array, $level);
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
            $msg = new AMQPMessage((string)json_encode($array), array(
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_NON_PERSISTENT,
                'correlation_id' => Uuid::uuid4()->toString()
            ));
            $this->channel->basic_publish($msg, $exchange, $routingKey);
        } elseif ($this->schedulerConnected) {// Since we're not connected to rabbit. Let's send it the scheduler.
            $array['time'] = Carbon::NOW()->addSeconds($this->reconnect_timeout)->timestamp;
            $array['routing_key'] = $routingKey;
            $this->createSchedule($array);
        } else { // We're not connected to either the scheduler or rabbit? Need to report to STDERR
            $time = Carbon::NOW();
            fwrite(STDERR, $time->format(self::TIMEFORMAT) . ' Rabbit and scheduler unavailable.' . PHP_EOL);
            fwrite(STDERR, $time->format(self::TIMEFORMAT) . ' ' . $array['stackdriver']['message'] . PHP_EOL);
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

    public function exceptionErrorHandler($errno, $errstr, $errfile, $errline)
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
        trigger_error('CheckSQLConnection is deprecated', E_USER_DEPRECATED);
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
            $messageBody = $this->getMessageObject(true);
            $messageBody['time'] = $time;
            $messageBody['routing_key'] = $routingKey;
            $this->req->body = json_encode($messageBody);
            $this->reschedule();
        }
    }

    public function getMessageRoutingKey()
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
                    $this->setExchange($_ENV['RMQ_EXCHANGE']);
                }
                if (empty($this->queue)) {
                    throw new Exception('No Queue has been set. Did you run $service->setQueue($queue) ?');
                }
                if (empty($this->routingKey)) {
                    throw new Exception('No Binding key been set. Did you run $service->bindQueue($routing_key) ?');
                }
                $this->setQueue($this->queue);
                $this->bindQueue($this->routingKey);
                $this->consume($this->name . '-' . Uuid::uuid4()->toString());
                while (count($this->channel->callbacks)) {
                    $this->channel->wait();
                }
            } catch (ErrorException $e) {
                $this->log($e->getFile() . ": " . $e->getLine(), self::CRITICAL);
                $this->log($e->getMessage(), self::CRITICAL);
                $this->reconnect();
            } catch (AMQPBasicCancelException | AMQPAMQPTimeoutException | AMQPIOException | AMQPRuntimeException $e) {
                $this->setConnected(false);
                $this->log('Rabbit Timeout ' . $e->getMessage(), self::CRITICAL);
                $this->reconnect();
            } catch (Exception | AMQPProtocolChannelException $e) {
                $this->setConnected(false);
                $this->log($e->getFile() . ": " . $e->getLine(), self::CRITICAL);
                $this->log($e->getMessage(), self::CRITICAL, $e);
                exit;
            }
        }
    }

    /**
     * @param bool $asArray
     * @return mixed
     */
    public function getMessageObject(bool $asArray = false)
    {
        return json_decode($this->req->body, $asArray);
    }
}
