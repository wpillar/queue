<?php
namespace Graze\Queue\Adapter;

use Aws\Sqs\SqsClient;
use Graze\Queue\Adapter\Exception\FailedAcknowledgementException;
use Graze\Queue\Adapter\Exception\FailedEnqueueException;
use Graze\Queue\Message\MessageFactoryInterface;
use Graze\Queue\Message\MessageInterface;

/**
 * Amazon AWS SQS Adapter
 *
 * @link http://docs.aws.amazon.com/aws-sdk-php/guide/latest/service-sqs.html
 * @link http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.Sqs.SqsClient.html#_createQueue
 * @link http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.Sqs.SqsClient.html#_deleteMessageBatch
 * @link http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.Sqs.SqsClient.html#_receiveMessage
 * @link http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.Sqs.SqsClient.html#_sendMessageBatch
 */
class SqsAdapter implements AdapterInterface
{
    const BATCHSIZE_DELETE  = 10;
    const BATCHSIZE_RECEIVE = 10;
    const BATCHSIZE_SEND    = 10;

    /**
     * @param SqsClient
     */
    protected $client;

    /**
     * @param array
     */
    protected $options;

    /**
     * @param string
     */
    protected $name;

    /**
     * @param string
     */
    protected $url;

    /**
     * @param SqsClient $client
     * @param string $name
     * @param array $options
     *     - DelaySeconds <integer> The time in seconds that the delivery of all
     *       messages in the queue will be delayed
     *     - MaximumMessageSize <integer> The limit of how many bytes a message
     *       can contain before Amazon SQS rejects it.
     *     - MessageRetentionPeriod <integer> The number of seconds Amazon SQS
     *       retains a message.
     *     - Policy <string> The queue's policy. A valid form-url-encoded policy.
     *     - ReceiveMessageWaitTimeSeconds <integer> The time for which a
     *       ReceiveMessage call will wait for a message to arrive.
     *     - VisibilityTimeout <integer> The visibility timeout for the queue.
     */
    public function __construct(SqsClient $client, $name, array $options = [])
    {
        $this->client = $client;

        $this->name = $name;
        $this->options = $options;
    }

    /**
     * {@inheritdoc}
     */
    public function acknowledge(array $messages)
    {
        $url = $this->getQueueUrl();
        $failed = [];
        $batches = array_chunk($this->createDeleteEntries($messages), self::BATCHSIZE_DELETE);

        foreach ($batches as $batch) {
            $results = $this->client->deleteMessageBatch([
                'QueueUrl' => $url,
                'Entries' => $batch
            ]);

            $failed = array_merge($failed, array_map(function ($result) use ($messages) {
                return $messages[$result['Id']];
            }, $results->getPath('Failed')));
        }

        if (!empty($failed)) {
            throw new FailedAcknowledgementException($this, $failed);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function dequeue(MessageFactoryInterface $factory, $limit)
    {
        $batches = (int) ceil($limit / self::BATCHSIZE_RECEIVE);

        for ($i = 1; $i <= $batches; $i++) {
            $lastBatch = $batches === $i;
            $batchSize = $lastBatch ? ($limit % self::BATCHSIZE_RECEIVE) : self::BATCHSIZE_RECEIVE;
            $timestamp = time() + $this->getQueueVisibilityTimeout();
            $validator = function () use ($timestamp) {
                return time() > $timestamp;
            };

            $results = $this->client->receiveMessage(array_filter([
                'QueueUrl' => $this->getQueueUrl(),
                'AttributeNames' => 'All',
                'MaxNumberOfMessages' => $batchSize,
                'VisibilityTimeout' => $this->getOption('VisibilityTimeout'),
                'WaitTimeSeconds' => $this->getOption('ReceiveMessageWaitTimeSeconds')
            ]));

            foreach ($results->getPath('Messages') as $result) {
                yield $factory->createMessage($result['Body'], [
                    'metadata' => $this->createMessageMetadata($result),
                    'validator' => $validator
                ]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function enqueue(array $messages)
    {
        $url = $this->getQueueUrl();
        $failed = [];
        $batches = array_chunk($this->createEnqueueEntries($messages), self::BATCHSIZE_SEND);

        foreach ($batches as $batch) {
            $results = $this->client->sendMessageBatch([
                'QueueUrl' => $url,
                'Entries' => $batch
            ]);

            $failed = array_merge($failed, array_map(function ($result) use ($messages) {
                return $messages[$result['Id']];
            }, $results->getPath('Failed')));
        }

        if (!empty($failed)) {
            throw new FailedEnqueueException($this, $failed);
        }
    }

    /**
     * @param MessageInterface[] $messages
     * @return array
     */
    protected function createDeleteEntries(array $messages)
    {
        array_walk($messages, function (MessageInterface &$message, $id) {
            $metadata = $message->getMetadata();
            $receipt = isset($metadata['ReceiptHandle']) ? $metadata['ReceiptHandle'] : null;

            $message = [
                'Id' => $id,
                'ReceiptHandle' => $receipt
            ];
        });

        return $messages;
    }

    /**
     * @param MessageInterface[] $messages
     * @return array
     */
    protected function createEnqueueEntries(array $messages)
    {
        array_walk($messages, function (MessageInterface &$message, $id) {
            $metadata = $message->getMetadata();
            $attributes = isset($metadata['MessageAttributes']) ? $metadata['MessageAttributes'] : [];

            $message = [
                'Id' => $id,
                'MessageBody' => $message->getBody(),
                'MessageAttributes' => $attributes
            ];
        });

        return $messages;
    }

    /**
     * @param array $result
     * @return array
     */
    protected function createMessageMetadata(array $result)
    {
        return [
            'Attributes' => $result['Attributes'],
            'MessageAttributes' => $result['MessageAttributes'],
            'MessageId' => $result['MessageId'],
            'ReceiptHandle' => $result['ReceiptHandle']
        ];
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    protected function getOption($name, $default = null)
    {
        return isset($this->options[$name]) ? $this->options[$name] : $default;
    }

    /**
     * @return string
     */
    protected function getQueueUrl()
    {
        if (!$this->url) {
            $result = $this->client->createQueue([
                'QueueName' => $this->name,
                'Attributes' => $this->options
            ]);

            $this->url = $result->getPath('QueueUrl');
        }

        return $this->url;
    }

    /**
     * @return integer
     */
    protected function getQueueVisibilityTimeout()
    {
        if (!isset($this->options['VisibilityTimeout'])) {
            $result = $this->client->getQueueAttributes([
                'QueueUrl' => $this->getQueueUrl(),
                'AttributeNames' => ['VisibilityTimeout']
            ]);

            $attributes = $result->getPath('Attributes');
            $this->options['VisibilityTimeout'] = $attributes['VisibilityTimeout'];
        }

        return $this->options['VisibilityTimeout'];
    }
}