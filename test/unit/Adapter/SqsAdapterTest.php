<?php
namespace Graze\Queue\Adapter;

use Mockery as m;
use PHPUnit_Framework_TestCase as TestCase;

class SqsAdapterTest extends TestCase
{
    public function setUp()
    {
        $this->client = m::mock('Aws\Sqs\SqsClient');
        $this->model = m::mock('Guzzle\Service\Resource\Model');
        $this->factory = m::mock('Graze\Queue\Message\MessageFactoryInterface');

        $this->messageA = $a = m::mock('Graze\Queue\Message\MessageInterface');
        $this->messageB = $b = m::mock('Graze\Queue\Message\MessageInterface');
        $this->messageC = $c = m::mock('Graze\Queue\Message\MessageInterface');
        $this->messages = [$a, $b, $c];
    }

    protected function stubCreateQueue($name, array $options = [])
    {
        $url = 'foo://bar';
        $model = m::mock('Guzzle\Service\Resource\Model');
        $model->shouldReceive('getPath')->once()->with('QueueUrl')->andReturn($url);

        $this->client->shouldReceive('createQueue')->once()->with([
            'QueueName' => $name,
            'Attributes' => $options
        ])->andReturn($model);

        return $url;
    }

    protected function stubQueueVisibilityTimeout($url)
    {
        $timeout = 120;
        $model = m::mock('Guzzle\Service\Resource\Model');
        $model->shouldReceive('getPath')->once()->with('Attributes')->andReturn(['VisibilityTimeout'=>$timeout]);

        $this->client->shouldReceive('getQueueAttributes')->once()->with([
            'QueueUrl' => $url,
            'AttributeNames' => ['VisibilityTimeout']
        ])->andReturn($model);

        return $timeout;
    }

    public function testInterface()
    {
        $this->assertInstanceOf('Graze\Queue\Adapter\AdapterInterface', new SqsAdapter($this->client, 'foo'));
    }

    public function testAcknowledge()
    {
        $adapter = new SqsAdapter($this->client, 'foo');
        $url = $this->stubCreateQueue('foo');

        $this->messageA->shouldReceive('getMetadata')->once()->withNoArgs()->andReturn(['ReceiptHandle'=>'foo']);
        $this->messageB->shouldReceive('getMetadata')->once()->withNoArgs()->andReturn(['ReceiptHandle'=>'bar']);
        $this->messageC->shouldReceive('getMetadata')->once()->withNoArgs()->andReturn(['ReceiptHandle'=>'baz']);

        $this->model->shouldReceive('getPath')->once()->with('Failed')->andReturn([]);

        $this->client->shouldReceive('deleteMessageBatch')->once()->with([
            'QueueUrl' => $url,
            'Entries' => [
                ['Id'=>0, 'ReceiptHandle'=>'foo'],
                ['Id'=>1, 'ReceiptHandle'=>'bar'],
                ['Id'=>2, 'ReceiptHandle'=>'baz']
            ]
        ])->andReturn($this->model);

        $adapter->acknowledge($this->messages);
    }

    public function testDequeue()
    {
        $adapter = new SqsAdapter($this->client, 'foo');
        $url = $this->stubCreateQueue('foo');
        $timeout = $this->stubQueueVisibilityTimeout($url);

        $this->factory->shouldReceive('createMessage')->once()->with('foo', m::on(function ($opts) {
            $meta = ['Attributes'=>[], 'MessageAttributes'=>[], 'MessageId'=>0, 'ReceiptHandle'=>'a'];
            $validator = isset($opts['validator']) && is_callable($opts['validator']);
            return isset($opts['metadata']) && $opts['metadata'] === $meta && $validator;
        }))->andReturn($this->messageA);
        $this->factory->shouldReceive('createMessage')->once()->with('bar', m::on(function ($opts) {
            $meta = ['Attributes'=>[], 'MessageAttributes'=>[], 'MessageId'=>1, 'ReceiptHandle'=>'b'];
            $validator = isset($opts['validator']) && is_callable($opts['validator']);
            return isset($opts['metadata']) && $opts['metadata'] === $meta && $validator;
        }))->andReturn($this->messageB);
        $this->factory->shouldReceive('createMessage')->once()->with('baz', m::on(function ($opts) {
            $meta = ['Attributes'=>[], 'MessageAttributes'=>[], 'MessageId'=>2, 'ReceiptHandle'=>'c'];
            $validator = isset($opts['validator']) && is_callable($opts['validator']);
            return isset($opts['metadata']) && $opts['metadata'] === $meta && $validator;
        }))->andReturn($this->messageC);

        $this->model->shouldReceive('getPath')->once()->with('Messages')->andReturn([
            ['Body'=>'foo', 'Attributes'=>[], 'MessageAttributes'=>[], 'MessageId'=>0, 'ReceiptHandle'=>'a'],
            ['Body'=>'bar', 'Attributes'=>[], 'MessageAttributes'=>[], 'MessageId'=>1, 'ReceiptHandle'=>'b'],
            ['Body'=>'baz', 'Attributes'=>[], 'MessageAttributes'=>[], 'MessageId'=>2, 'ReceiptHandle'=>'c']
        ]);

        $this->client->shouldReceive('receiveMessage')->once()->with([
            'QueueUrl' => $url,
            'AttributeNames' => 'All',
            'MaxNumberOfMessages' => 3,
            'VisibilityTimeout' => $timeout
        ])->andReturn($this->model);

        $this->assertEquals($this->messages, iterator_to_array($adapter->dequeue($this->factory, 3)));
    }

    public function testEnqueue()
    {
        $adapter = new SqsAdapter($this->client, 'foo');
        $url = $this->stubCreateQueue('foo');

        $this->messageA->shouldReceive('getBody')->once()->withNoArgs()->andReturn('foo');
        $this->messageB->shouldReceive('getBody')->once()->withNoArgs()->andReturn('bar');
        $this->messageC->shouldReceive('getBody')->once()->withNoArgs()->andReturn('baz');
        $this->messageA->shouldReceive('getMetadata')->once()->withNoArgs()->andReturn(null);
        $this->messageB->shouldReceive('getMetadata')->once()->withNoArgs()->andReturn(null);
        $this->messageC->shouldReceive('getMetadata')->once()->withNoArgs()->andReturn(null);

        $this->model->shouldReceive('getPath')->once()->with('Failed')->andReturn([]);

        $this->client->shouldReceive('sendMessageBatch')->once()->with([
            'QueueUrl' => $url,
            'Entries' => [
                ['Id'=>0, 'MessageBody'=>'foo', 'MessageAttributes'=>[]],
                ['Id'=>1, 'MessageBody'=>'bar', 'MessageAttributes'=>[]],
                ['Id'=>2, 'MessageBody'=>'baz', 'MessageAttributes'=>[]]
            ]
        ])->andReturn($this->model);

        $adapter->enqueue($this->messages);
    }
}