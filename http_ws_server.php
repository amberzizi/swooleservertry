<?php
/*
*系统内通讯服务器端
*swoole+redis
*@author amberhu
*/
class WebsocketTest {
    public $server;
    protected $redis_connect;

    public function __construct() {
	//redis
	$this->get_redis_object();
        $this->server = new swoole_websocket_server("0.0.0.0", 29051);
        $this->server->on('open',function (swoole_websocket_server $server, $request){
	    $this->onOpen($server,$request);
	  });
        $this->server->on('message', function (swoole_websocket_server $server, $frame) {
	    $this->onMessage($server,$frame);
          });
        $this->server->on('close', function ($ser, $fd) {
            $this->onClose($ser, $fd);
          });
        $this->server->on('request', function ($request, $response) {
                // 接收http请求从get获取message参数的值，给用户推送
                // $this->server->connections 遍历所有websocket连接用户的fd，给所有用户推送
                foreach ($this->server->connections as $fd) {
                    $this->server->push($fd, $request->get['message']);
                }
          });
	$this->server_config();
        $this->server->start();
	
	
    }
    
    protected function server_config(){

	$this->server->set(array(
	    //'reactor_num' => 2, //reactor thread num
	    'worker_num' => 2,    //worker process num
	    'backlog' => 128,   //listen backlog
	    'max_request' => 200,
	    'dispatch_mode' => 5,
	));

    }
	
    //CREATE REDIS DATABASE CONNECT
    protected function get_redis_object(){

	$this->redis_connect = new Redis();
	$this->redis_connect->connect('127.0.0.1',6379);
	$this->redis_connect->auth('2015ccicc');
	$this->redis_connect->select(2);
    }
    //TRY MAKE SURE REDIS CONNECT STATUS
    protected function make_sure_redis_connect(){
	try{
		$this->redis_connect->ping();
	}catch(Exception $e){
  		$this->get_redis_object();
	}
    }
// connect redis db get info
    protected function get_redis_info($fd,$type){
		
		//save fd=>name to db
		$re = $this->redis_connect->get($fd);
		if($re){
			$name_arr = explode('FDK',$re);
			if($type == 'name'){
				return $name_arr[0];	
			}else if($type == 'phone'){
				return $name_arr[1];	
			}else if($type == 'uid'){
				return $name_arr[2];	
			}else if($type == 'belongfd'){
				return $name_arr[3];	
			}
				
		}
		return 'novalue';
    }
    protected function change_redis_info($fd,$type,$change){
		//save fd=>name to db
		$re = $this->redis_connect->get($fd);
		if($re){
			$name_arr = explode('FDK',$re);
			if($type == 'name'){
				$name_arr[0] = $change;	
			}else if($type == 'phone'){
				$name_arr[1] = $change;		
			}else if($type == 'uid'){
				$name_arr[2] = $change;	
			}else if($type == 'belongfd'){
				$name_arr[3] = $change;	
			}
			$name_str = implode('FDK',$name_arr);
			$this->redis_connect->set($fd,$name_str);
		}

    }
/*
* save all chat records to redis database
* @param message json
*/
    protected function save_chat_record_to_redis($record_jeson){

	$nowdate = date('Ymd');
	$ta_key = 'MESSAGE'.$nowdate;
	if($this->redis_connect->exists($ta_key)){
			
		$this->redis_connect->append($ta_key,'NEXT'.$record_jeson);
	}else{

		$this->redis_connect->set($ta_key,$record_jeson);

	}

    }
/*
* save private chat records (not received) to redis database
* @param uid (receive one)
* @param message json
*/	
    protected function save_chart_record_noreceive_to_redis($uid,$record_jeson){

	$ta_key = 'PMESSAGE'.$uid;
        if($this->redis_connect->get($ta_key)){
			
		$this->redis_connect->append($ta_key,'NEXT'.$record_jeson);
	}else{

		$this->redis_connect->set($ta_key,$record_jeson);

	}

    }
/*
* check if have private message didn't received  ,send message again ,delete key
*@param uid
*@param fd
*/
    protected function check_if_had_didt_receive_messages($uid,$fd){

	$ta_key = 'PMESSAGE'.$uid;
        if($this->redis_connect->get($ta_key)){
			
		$re = $this->redis_connect->get($ta_key);
		$re_arr = explode('NEXT',$re);
		if(count($re_arr) > 0){
			
			foreach($re_arr as $vae){
				$temp_obj = json_decode($vae);
				$temp_obj->cmd = "send_message_private_sendagain";
				$temp_obj_json = json_encode($temp_obj);
				$this->server->push($fd,$temp_obj_json);
			}

			
			$this->redis_connect->delete($ta_key);
		}
	}
	
		
    }

    //CLINET CONNECT SERVER SUCCESS 	
    public function onOpen (swoole_websocket_server $server, $request) {
        //echo "server: handshake success with fd{$request->fd}\n";
	//connect success 
	$back_value = new SendObject();
	$back_value->cmd = 'login_config_s';
	$back_value->fd = $request->fd;
	$back_value_json = json_encode($back_value);	
	
	$this->server->push($request->fd, $back_value_json);
	//$this->save_chat_record_to_redis($back_value_json);
    }


    //Message deal hub
    public function onMessage(swoole_websocket_server $server, $frame){
	$send_num = 'all';
	$get_data = $frame->data;
	$get_data_obj = json_decode($get_data);
	$cmd = $get_data_obj->cmd;
	$key_v = array();
	$back_value_json = '';

		//1.send welcome to all member.
		//2.update member list and mumber num.
	if($cmd == 'login'){
		//TRY MAKE SURE REDIS CONNECT STATUS
	     	$this->make_sure_redis_connect();

		//check user uid if had bind
		$fd_belong = $frame->fd;
		$fd_father = '';	
		foreach ($this->server->connections as $fd) {
			$temp_info = $this->server->connection_info($fd);
			if($get_data_obj->uid == $temp_info['uid']){
				$fd_father = $fd;
				break;
			}	
		}
		
		

		if($fd_father != ''){
		//had bind before
			//append key-value
			$this->redis_connect->append('FD'.$fd_father,','.$frame->fd);
			//for back search
			$this->redis_connect->set('FBD'.$frame->fd,$fd_father);

			$send_num = 'onlyone';
		}else{
			//main line
			//bind uid to server
			$this->server->bind($frame->fd,$get_data_obj->uid);
			//save fd=>nameFDKphoneFDKuid to db
			$this->redis_connect->set('FD'.$frame->fd,$get_data_obj->name.
							'FDK'.$get_data_obj->phone.
							'FDK'.$get_data_obj->uid.
							'FDK'.$fd_belong);

			//after login check if had any message did't read
			//send message
			//delete key
			$this->check_if_had_didt_receive_messages($get_data_obj->uid,$frame->fd);
		}


		
		
		
		//member list
		//get all member list
		$all_key = $this->redis_connect->keys('FD*');
	
		foreach($all_key as $kv){
			$key_v[$kv] = $this->redis_connect->get($kv);	
		}	

	

		$back_value = new SendObject();
		$back_value->cmd = 'login_welcome_s';
		$back_value->send_name = $get_data_obj->name;
		$back_value->login_uid = $get_data_obj->uid;
		$back_value->fd = $frame->fd;
		if($send_num == 'all'){
			$back_value->online_num = count($this->server->connections);
		}else if($send_num == 'onlyone'){
			$back_value->online_num = count($this->server->connections) - 1;
		}
		
		$back_value->online_member = $key_v;
		$back_value->send_time = date("Y-m-d H:i:s");
		$back_value_json = json_encode($back_value);	

		
		

	}else if($cmd == 'message'){
		//to all  -99
		//to someone  forexample:FD110 FD55

               if($get_data_obj->mid == '-99'){

			//to public

			$back_value = new SendObject();
			
			if($this->redis_connect->get('FBD'.$frame->fd)){
				$fd_father = $this->redis_connect->get('FBD'.$frame->fd);
				$back_value->send_name = $this->get_redis_info('FD'.$fd_father,'name');
				$back_value->phone = $this->get_redis_info('FD'.$fd_father,'phone');
				$back_value->fd_belong = $this->get_redis_info('FD'.$fd_father,'belongfd');
				$back_value->fa_father = $fd_father;

			}else if($this->redis_connect->get('FD'.$frame->fd)){
				$back_value->send_name = $this->get_redis_info('FD'.$frame->fd,'name');
				$back_value->phone = $this->get_redis_info('FD'.$frame->fd,'phone');
				$back_value->fd_belong = $this->get_redis_info('FD'.$frame->fd,'belongfd');
				$back_value->fa_father = $frame->fd;

			}
		
			$back_value->cmd = 'send_message_s';
			$back_value->fd = $frame->fd;
			$back_value->type = $get_data_obj->type;
			$back_value->send_data = $get_data_obj->data;
			$back_value->send_time = date("Y-m-d H:i:s");
			$back_value_json = json_encode($back_value);


		}else{
			//to private
			$receive_online = '1';
			$receive_m_str = $get_data_obj->mid;
			$receive_m_arr = explode('D',$receive_m_str);
			//var_dump($receive_m_arr);die();
			//collect information for private chat
			//(1)base info			
			$back_value = new SendObject();
			$back_value->cmd = 'send_message_private_s';
			$back_value->type = $get_data_obj->type;
			$back_value->send_data = $get_data_obj->data;
			//target uid and uname
			//important!
			$back_value->target_uid = $get_data_obj->target_uid;
			$back_value->target_uname = $get_data_obj->target_uname;
			$back_value->send_time = date("Y-m-d H:i:s");
			
			//(2)message send person's fd and name
                        //   message receive person's fd and name
			$fa_father = $frame->fd;
			if($ffd = $this->redis_connect->get('FBD'.$frame->fd)){
				$fa_father = $ffd;
			}
			$back_value->send_fd = $fa_father;
			$back_value->send_name = $this->get_redis_info('FD'.$fa_father,'name');
			$back_value->send_phone = $this->get_redis_info('FD'.$fa_father,'phone');	
			$back_value->send_fd_belong = $this->get_redis_info('FD'.$fa_father,'belongfd');
			$back_value->receive_fd = $receive_m_arr[1];
			$back_value->receive_name = $this->get_redis_info('FD'.$receive_m_arr[1],'name');
			
			//(3)make sure that the receive person if online ,1=>online   0=>not online			
			if($back_value->receive_name == 'novalue'){
				$receive_online = '0';
			}

			

			//send private message
			if($receive_online == '1'){

			//send to send(include sub line)  and receive person
				$back_value_json = json_encode($back_value);
			
				//save message:private chat receiveonline
				$this->save_chat_record_to_redis($back_value_json);
				
				$send_fd_sub_str = $this->get_redis_info('FD'.$fa_father,'belongfd');
				$send_fd_sub_arr = explode(',',$send_fd_sub_str);
				$receive_fd_sub_str = $this->get_redis_info('FD'.$receive_m_arr[1],'belongfd');
				$receive_fd_sub_arr = explode(',',$receive_fd_sub_str);

				foreach($send_fd_sub_arr as $sv){

					$this->server->push($sv, $back_value_json);

				}
				//var_dump($back_value->send_fd);
				//var_dump($back_value->receive_fd);
				//if send one == receive one ,then don't send two times
				if($back_value->send_fd != $back_value->receive_fd){

					foreach($receive_fd_sub_arr as $rv){

						$this->server->push($rv, $back_value_json);


					}

				}
				
			
			}else{
			//only send to sendone that "receive one not online"
				$back_value->cmd = 'send_message_private_notonline_s';
				$back_value_json = json_encode($back_value);
			
				//save message:private chat receive not online
				$this->save_chat_record_to_redis($back_value_json);
				//save private message for didn't receive
				$this->save_chart_record_noreceive_to_redis($back_value->target_uid,$back_value_json);				

				$send_fd_sub_str = $this->get_redis_info('FD'.$fa_father,'belongfd');
				$send_fd_sub_arr = explode(',',$send_fd_sub_str);

				foreach($send_fd_sub_arr as $sv){

					$this->server->push($sv, $back_value_json);


				}

				

			}

		
			return;

		}


	}
	
	//save message :login/chat-public
	$this->save_chat_record_to_redis($back_value_json);
	if($send_num == 'all'){
		if($back_value_json != ''){
			foreach ($this->server->connections as $fd) {
		            $this->server->push($fd, $back_value_json);
			}
		}

	}else{
		if($back_value_json != ''){
			
		            $this->server->push($frame->fd, $back_value_json);

		}


	}
		
	

    }

    
	//1.send leave to all member.
	//2.update member list and member num
	//3.if main fd close then close all FD
    public function onClose($server,$fd){
	
	//TRY MAKE SURE REDIS CONNECT STATUS
     	$this->make_sure_redis_connect();
	$key_v = array();
	$back_value_json = '';

	//delete fd=>name to db
	if($this->redis_connect->get('FD'.$fd)){
		//when main fd close then close other sub fb arr
		$belong = $this->get_redis_info('FD'.$fd,'belongfd');
		$belong_arr = explode(',',$belong);
		foreach($belong_arr as $v){
			if($v != $fd){

				$back_value = new SendObject();
				$back_value->cmd = 'logout_s_sub';
				//Get Client Name from session via fd

				$back_value->send_name = $this->get_redis_info('FD'.$fd,'name');

				$back_value_json = json_encode($back_value);

			
				$this->server->push($v, $back_value_json);
				
				$this->server->close($v);


			}	
		}		
		
		$close_name = $this->get_redis_info('FD'.$fd,'name');
		$this->redis_connect->del('FD'.$fd);
		//get all member list
		$all_key = $this->redis_connect->keys('FD*');
	
		foreach($all_key as $kv){
			$key_v[$kv] = $this->redis_connect->get($kv);	
		}

			$back_value = new SendObject();
			$back_value->cmd = 'logout_s';
			//Get Client Name from session via fd
			$back_value->online_num = count($this->server->connections) - 1;
			$back_value->online_member = $key_v;	
			$back_value->send_name = $close_name;
			$back_value->send_time = date("Y-m-d H:i:s");
			$back_value_json = json_encode($back_value);

			foreach ($this->server->connections as $fdc) {
				if($fdc == $fd){
					continue;		
				}
				$this->server->push($fdc, $back_value_json);
		        }


	}else if($this->redis_connect->get('FBD'.$fd)){
		//if this fb is a sub fb,1.update main (FD)fb data,2.delete this FBDfb	
		
		$main_fd_info = $this->redis_connect->get('FBD'.$fd);
		$ifstillonline = $this->redis_connect->get('FD'.$main_fd_info);
		if($ifstillonline){
			$belong = $this->get_redis_info('FD'.$main_fd_info,'belongfd');
			$belong_arr = explode(',',$belong);
			for($i=0;$i<count($belong_arr);$i++){
				if($belong_arr[$i] == $fd){
					unset($belong_arr[$i]);
				}		
			}
			$belong = implode(',',$belong_arr);

			//update main FD value
		        $this->change_redis_info('FD'.$main_fd_info,'belongfd',$belong);
			//delete sub FD
			$this->redis_connect->del('FBD'.$fd);

		}
		
		
		
	}

    }

        
}
class SendObject{
}

//sesstion_start(); 
date_default_timezone_set("PRC");

new WebsocketTest();


