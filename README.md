# Datanoodle Core PHP Service

[![Codacy Badge](https://api.codacy.com/project/badge/Grade/c0ff7843d7d9482fb815711c048fbfb0)](https://www.codacy.com/app/Datanoodle/dn-service-core)
[![Build Status](https://travis-ci.com/datanoodle-com/dn-service-core.svg?branch=master)](https://travis-ci.com//datanoodle-com/dn-service-core)
[![Codacy Badge](https://api.codacy.com/project/badge/Coverage/c0ff7843d7d9482fb815711c048fbfb0)](https://www.codacy.com/app/Datanoodle/dn-service-core?utm_source=github.com&utm_medium=referral&utm_content=datanoodle-com/dn-service-core)

This is the core PHP Service. This service should not be invoked directly. Instead you should extend this service in your own service. 


In your own project's composer.json you should add the following code. 

```
"repositories": [
       {
         "type": "vcs",
         "url": "git@github.com:datanoodle-com/dn-service-core.git",
         "no-api": true
       }
     ]
```

Once you've added the above code to your composer.json you can install the core service. 

```
composer require datanoodle/dn-service-core
```

This core service provides the base funcationality for

* Connecting to RabbbitMQ (This service handles the following automatically)
    * Logging
    * Disconnection/Reconnection
    * Scheduling
* Connecting to the database

This means your service only has to worry about handling messages received from rabbitMQ.

Your PHP service `must` implement a `processSuccess()` function.

Any messages that are received by the core service will be stored in the '$this->req->body' property.

## Environment variables

This service relies heavily on .env file for configuration. 

The sample .env file provided lists all the flags required by the core service.

You can add your own parameters to the .env as required for your own services. 

## Logging

You can toggle logging to STDOUT by setting the STD_LOGGING flag in the .env file.

e.g. 
```
STD_LOGGING=false # Will log only to stackdriver
```
```
STD_LOGGING=true # Will log to both stackdriver and to STDOUT
```

Note:  There is no way to disable stackdriver logging.

Logging messages is handled by the 'log($message, $level)' function in Service.php
This function takes 2 parameters

* Message (required) The message to log.
* Level (optional) The level of the message. The default level if no level is provided is INFO (See Service.php for the full list of accepted log levels).

e.g. 

```
$this->log('This is a normal info log');
$this->log(('Explicitly set an info level', Service::INFO);
// Set a debug message
$this->log('A Debug message', self::DEBUG');
```

e.g. 

`TutorialService.php`
```
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Datanoodle\Service;

class TutorialService extends Service {
    public function processSuccess() {
        // Decode the received message into a std PHP object
        $message = json_decode($this->req->body);
        // Determine the sending routing key
        $this->log('The routing key used was '. $this-getMessageRoutingKey());
        
        // Do something awesome, and write your own logic.        
    }    
}

$tutorialService = new TutorialService('tutorial-svc');
// Set the exchange for the service.
$tutorialService->setExchange(getenv('RMQ_EXCHANGE'));
// Set the queue fro the service
$tutorialService->setQueue('tutorial-svc-queue');
// Bind this queue to as many routing_keys as you like
$tutorialService->bindQueue('routing_key.1');
$tutorialService->bindQueue('routing_key.2');
$tutorialService->bindQueue('another,key');
// Finally run the service.
$tutorialService->runService();
```
