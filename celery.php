<?php

/**
 * This file contains a PHP client to Celery distributed task queue
 *
 * LICENSE: 2-clause BSD
 *
 * Copyright (c) 2012, GDR!
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met: 
 * 
 * 1. Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer. 
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution. 
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 * 
 * The views and conclusions contained in the software and documentation are those
 * of the authors and should not be interpreted as representing official policies, 
 * either expressed or implied, of the FreeBSD Project. 
 *
 * @link http://massivescale.net/
 * @link http://gdr.geekhood.net/
 * @link https://github.com/gjedeer/celery-php
 *
 * @package celery-php
 * @license http://opensource.org/licenses/bsd-license.php 2-clause BSD
 * @author GDR! <gdr@go2.pl>
 */

/**
 * General exception class
 * @package celery-php
 */
class CeleryException extends Exception {};
/**
 * Emited by AsyncResult::get() on timeout
 * @package celery-php
 */
class CeleryTimeoutException extends CeleryException {};
class CeleryPublishException extends CeleryException {};

require('amqp.php');

class CeleryWithBackend extends CeleryAbstract 
{
	function __construct($brokerConnection, $backendConnection=false) 
	{
		if ($backendConnection == false) { $backendConnection = $brokerConnection; }	
	
		$items = $this->buildConnection($brokerConnection);
		$items = $this->buildConnection($backendConnection, true);
	}
}

class Celery extends CeleryAbstract 
{
	function __construct($host, $login, $password, $vhost, $exchange='celery', $binding='celery', $port=5672, $connector = false, $persistent_messages=false, $result_expire=0, $ssl_options = array() )
	{
		$brokerConnection = array(
			'host' => $host,
			'login' => $login,
			'password' => $password,
			'vhost' => $vhost,
			'exchange' => $exchange,
			'binding' => $binding,
			'port' => $port,
			'connector' => $connector,
			'result_expire' => $result_expire,
			'ssl_options' => $ssl_options
		);
		$backendConnection = $brokerConnection;

		$items = $this->buildConnection($brokerConnection);
		$items = $this->buildConnection($backendConnection, true);
	}
}


/**
 * Client for a Celery server
 * @package celery-php
 */
abstract class CeleryAbstract
{

	private $broker_connection = null;
	private $broker_connection_details = array();
	private $broker_amqp = null;

	private $backend_conneciton = null;
	private $backend_connection_details = array();
	private $backend_amqp = null;

	private function setDefaultValues($details) {
		$defaultValues = array("host" => "", "login" => "", "password" => "", "vhost" => "", "exchange" => "celery", "binding" => "celery", "port" => 5672, "connector" => false, "persistent_messages" => false, "result_expire" => 0, "ssl_options" => array());

		$returnValue = array();
		foreach(array('host', 'login', 'password', 'vhost', 'exchange', 'binding', 'port', 'connector', 'persistent_messages', 'result_expire', 'ssl_options') as $detail)
		{
			if (!array_key_exists($detail, $details)) { $returnValue[$detail] = $defaultValues[$detail]; }
			else $returnValue[$detail] = $details[$detail];
		}
		return $returnValue;
	}

	public function buildConnection ($connectionDetails, $isBackend = false) {
		$connectionDetails = $this->setDefaultValues($connectionDetails);
		$ssl = !empty($connection['ssl_options']);

		if ($connectionDetails['connector'] === false)
		{
			$connectionDetails['connector'] = AbstractAMQPConnector::GetBestInstalledExtensionName($ssl);
		}
		$amqp = AbstractAMQPConnector::GetConcrete($connectionDetails['connector']);
		$connection = self::InitializeAMQPConnection($connectionDetails);
		$amqp->Connect($connection);

		if ($isBackend) {
			$this->backend_connection_details = $connectionDetails;
			$this->backend_connection = $connection;
			$this->backend_amqp = $amqp;
		}
		else {
			$this->broker_connection_details = $connectionDetails;
			$this->broker_connection = $connection;
			$this->broker_amqp = $amqp;
		}
	}

	static function InitializeAMQPConnection($details)
	{
		$amqp = AbstractAMQPConnector::GetConcrete($details['connector']);
		return $amqp->GetConnectionObject($details);
	}

	/**
	 * Post a task to Celery
	 * @param string $task Name of the task, prefixed with module name (like tasks.add for function add() in task.py)
	 * @param array $args Array of arguments (kwargs call when $args is associative)
	 * @return AsyncResult
	 */
	function PostTask($task, $args, $async_result=true,$routing_key="celery")
	{
		if(!is_array($args))
		{
			throw new CeleryException("Args should be an array");
		}
		$id = uniqid('php_', TRUE);

		/* $args is numeric -> positional args */
		if(array_keys($args) === range(0, count($args) - 1))
		{
			$kwargs = array();
		}
		/* $args is associative -> contains kwargs */
		else
		{
			$kwargs = $args;
			$args = array();
		}
                                                                            
		$task_array = array(
			'id' => $id,
			'task' => $task,
			'args' => $args,
			'kwargs' => (object)$kwargs,
		);
		$task = json_encode($task_array);
		$params = array('content_type' => 'application/json',
			'content_encoding' => 'UTF-8',
			'immediate' => false,
			);

		if($this->broker_connection_details['persistent_messages'])
		{
			$params['delivery_mode'] = 2;
		}

        $this->broker_connection_details['routing_key'] = $routing_key;

		$success = $this->broker_amqp->PostToExchange(
			$this->broker_connection,
			$this->broker_connection_details,
			$task,
			$params
		);

        if(!$success) 
        {
           throw new CeleryPublishException();
        }

        if($async_result) 
        {
			return new AsyncResult($id, $this->backend_connection_details, $task_array['task'], $args);
        } 
        else 
        {
			return true;
		}
	}
}

/*
 * Asynchronous result of Celery task
 * @package celery-php
 */
class AsyncResult 
{
	private $task_id; // string, queue name
	private $connection; // AMQPConnection instance
	private $connection_details; // array of strings required to connect
	private $complete_result; // Backend-dependent message instance (AMQPEnvelope or PhpAmqpLib\Message\AMQPMessage)
	private $body; // decoded array with message body (whatever Celery task returned)
	private $amqp = null; // AbstractAMQPConnector implementation

	/**
	 * Don't instantiate AsyncResult yourself, used internally only
	 * @param string $id Task ID in Celery
	 * @param array $connection_details used to initialize AMQPConnection, keys are the same as args to Celery::__construct
	 * @param string task_name
	 * @param array task_args
	 */
	function __construct($id, $connection_details, $task_name=NULL, $task_args=NULL)
	{
		$this->task_id = $id;
		$this->connection = Celery::InitializeAMQPConnection($connection_details);
		$this->connection_details = $connection_details;
		$this->task_name = $task_name;
		$this->task_args = $task_args;
		$this->amqp = AbstractAMQPConnector::GetConcrete($connection_details['connector']);
	}

	function __wakeup()
	{
		if($this->connection_details)
		{
			$this->connection = Celery::InitializeAMQPConnection($this->connection_details);
		}
	}

	/**
	 * Connect to queue, see if there's a result waiting for us
	 * Private - to be used internally
	 */
	private function getCompleteResult()
	{
		if($this->complete_result)
		{
			return $this->complete_result;
		}

		$message = $this->amqp->GetMessageBody($this->connection, $this->task_id,$this->connection_details['result_expire']);
		
		if($message !== false)
		{
			$this->complete_result = $message['complete_result'];
			$this->body = json_decode(
				$message['body']
			);
		}

		return false;
	}

	/**
	 * Helper function to return current microseconds time as float 
	 */
	static private function getmicrotime()
	{
		    list($usec, $sec) = explode(" ",microtime()); 
			return ((float)$usec + (float)$sec); 
	}

	/**
	 * Get the Task Id
	 * @return string
	 */
	 function getId()
	 {
	 	return $this->task_id;
	 }

	/**
	 * Check if a task result is ready
	 * @return bool
	 */
	function isReady()
	{
		return ($this->getCompleteResult() !== false);
	}

	/**
	 * Return task status (needs to be called after isReady() returned true)
	 * @return string 'SUCCESS', 'FAILURE' etc - see Celery source
	 */
	function getStatus()
	{
		if(!$this->body)
		{
			throw new CeleryException('Called getStatus before task was ready');
		}
		return $this->body->status;
	}

	/**
	 * Check if task execution has been successful or resulted in an error
	 * @return bool
	 */
	function isSuccess()
	{
		return($this->getStatus() == 'SUCCESS');
	}

	/**
	 * If task execution wasn't successful, return a Python traceback
	 * @return string
	 */
	function getTraceback()
	{
		if(!$this->body)
		{
			throw new CeleryException('Called getTraceback before task was ready');
		}
		return $this->body->traceback;
	}

	/**
	 * Return a result of successful execution.
	 * In case of failure, this returns an exception object
	 * @return mixed Whatever the task returned
	 */
	function getResult()
	{
		if(!$this->body)
		{
			throw new CeleryException('Called getResult before task was ready');
		}

		return $this->body->result;
	}

	/****************************************************************************
	 * Python API emulation                                                     *
	 * http://ask.github.com/celery/reference/celery.result.html                *
	 ****************************************************************************/

	/**
	 * Returns TRUE if the task failed
	 */
	function failed()
	{
		return $this->isReady() && !$this->isSuccess();
	}

	/**
	 * Forget about (and possibly remove the result of) this task
	 * Currently does nothing in PHP client
	 */
	function forget()
	{
	}

	/**
	 * Wait until task is ready, and return its result.
	 * @param float $timeout How long to wait, in seconds, before the operation times out
	 * @param bool $propagate (TODO - not working) Re-raise exception if the task failed.
	 * @param float $interval Time to wait (in seconds) before retrying to retrieve the result
	 * @throws CeleryTimeoutException on timeout
	 * @return mixed result on both success and failure
	 */
	function get($timeout=10, $propagate=TRUE, $interval=0.5)
	{
		/**
		 * This is an ugly workaround for PHP-AMQPLIB lack of support for fractional wait time
		 * @TODO remove the whole 'if' when php-amqp accepts https://github.com/videlalvaro/php-amqplib/pull/80
		 */
		$original_interval = $interval;
		if(property_exists($this->connection, 'wait_timeout'))
		{
			if($this->connection->wait_timeout < $interval)
			{
				$interval = 0;
			}
			else
			{
				$interval -= $this->connection->wait_timeout;
			}
		}

		$interval_us = (int)($interval * 1000000);
		$iteration_limit = ceil($timeout / $original_interval);

		$start_time = self::getmicrotime();
		while(self::getmicrotime() - $start_time < $timeout)
        {
                if($this->isReady())
                {
                        break;
                }

                usleep($interval_us);
        }

        if(!$this->isReady())
        {
                throw new CeleryTimeoutException(sprintf('AMQP task %s(%s) did not return after %d seconds', $this->task_name, json_encode($this->task_args), $timeout), 4);
        }

        return $this->getResult();
	}

	/**
	 * Implementation of Python's properties: result, state/status
	 */
	public function __get($property)
	{
		/**
		 * When the task has been executed, this contains the return value. 
		 * If the task raised an exception, this will be the exception instance.
		 */
		if($property == 'result')
		{
			if($this->isReady())
			{
				return $this->getResult();
			}
			else
			{
				return NULL;
			}
		}
		/**
		 * state: The tasks current state.
		 *
		 * Possible values includes:
		 *
		 * PENDING
		 * The task is waiting for execution.
		 *
		 * STARTED
		 * The task has been started.
		 *
		 * RETRY
		 * The task is to be retried, possibly because of failure.
		 *
		 * FAILURE
		 * The task raised an exception, or has exceeded the retry limit. The result attribute then contains the exception raised by the task.
		 *
		 * SUCCESS
		 * The task executed successfully. The result attribute then contains the tasks return value.
		 *
		 * status: Deprecated alias of state.
		 */
		elseif($property == 'state' || $property == 'status')
		{
			if($this->isReady())
			{
				return $this->getStatus();
			}
			else
			{
				return 'PENDING';
			}
		}

		return $this->$property;
	}

	/**
	 * Returns True if the task has been executed.
	 * If the task is still running, pending, or is waiting for retry then False is returned.
	 */
	function ready()
	{
		return $this->isReady();
	}

	/**
	 * Send revoke signal to all workers
	 * Does nothing in PHP client
	 */
	function revoke()
	{
	}

	/**
	 * Returns True if the task executed successfully.
	 */
	function successful()
	{
		return $this->isSuccess();
	}

	/**
	 * Deprecated alias to get()
	 */
	function wait($timeout=10, $propagate=TRUE, $interval=0.5)
	{
		return $this->get($timeout, $propagate, $interval);
	}
}

