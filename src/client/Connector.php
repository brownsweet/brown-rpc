<?php

/**
 *   Author:Brown
 *   Email: 455764041@qq.com
 *   Time: 2021/12/24 21:54
 */
namespace brown\client;


use brown\request\Request;
use brown\response\Response;
use brown\sendfile\FileBase;
use GuzzleHttp\Exception\ConnectException;
use Swoole\Client;
use brown\exceptions\RpcException;
trait Connector
{
    protected $parser;
    public function connect($proto='tcp')
    {
        if ($this->getConfig('rpc.client.register.enable')){
            $uri=$this->getConfig('rpc.client.register.uri');

            $Register=$this->getConfig('rpc.client.register.class');
            $r_w=new $Register($uri);
            $a=$r_w->getServices($this->services);
            $host=$a[0]->getHost();
            $port=$a[0]->getPort();
        }else{
            $config=$this->getConfig('rpc.client');
            if (!isset($config[$this->services])){
                throw new RpcException("服务不存在");
            }
            $host=$config[$this->services]['host'];
            $port=$config[$this->services]['port'];
        }

        $timeout=$this->getConfig('rpc.client.timeout');
        if ($proto=='tcp'){
            $client=new Client(SWOOLE_SOCK_TCP);
            if (!$client->connect($host,$port,$timeout)){
                throw new RpcException("连接失败");
            }
        }else{
            $client = new \GuzzleHttp\Client(['base_uri'=>$host.':'.$port,'timeout'=>$timeout]);

        }
        return $client;
    }

    public function send(Request $request){


        if (!$this->parser){
            $parser=$this->getConfig('parser.class');
            $this->parser=new $parser();
        }

        $data=$this->encodeData($request,$this->parser);

        try {
            $conn=$this->connect('http');

            $request = new \GuzzleHttp\Psr7\Request('POST', '/', [], $data);

            $response = $conn->send($request);

            $result = unserialize($response->getBody()->getContents());
        }catch (ConnectException $e){
            $conn=$this->connect();
            if (!$data instanceof \Generator){
                $data=[$data];
            }

            foreach ($data as $string) {

                if (!empty($string)) {

                    if ($conn->send($string) === false) {
                        throw new RpcException('Send data failed. ' .  $conn->errCode);
                    }
                }
            }
            $result=unserialize($conn->recv(65536,Client::MSG_WAITALL));
        }

        if (!($result instanceof Response)){
            throw new RpcException('错误的响应');
        }

        if ($result->code ==Response::RES_ERROR){
            throw new RpcException($result->msg);
        }
        return $result->data['result'];
    }

    protected function encodeData(Request $request,$parser)
    {
        $params = $request->getParams();

        //有文件,先传输
        foreach ($params as $index => $param) {
            if ($param instanceof FileBase) {
                $handle = fopen($param->getPathname(), 'rb');
                $file=[
                  $index=>  fread($handle, 8192)
                ];
                unset($params[$index]);

            }
        }
        if (isset($file)){
            $request->setFile($file);
        }
        $request->setParams($params);

        return $parser->pack($request);
    }
    public function sizecount($filesize) {
        if($filesize >= 1073741824) {
            $filesize = round($filesize / 1073741824 * 100) / 100 . ' gb';

        } elseif($filesize >= 1048576) {
            $filesize = round($filesize / 1048576 * 100) / 100 . ' mb';

        } elseif($filesize >= 1024) {
            $filesize = round($filesize / 1024 * 100) / 100 . ' kb';

        } else {
            $filesize = $filesize . ' bytes';

        }

        return $filesize;

    }
}