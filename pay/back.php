<?php
include '../baseInfo.php';
include '../config.php';
//==============================================================
if(isset($_GET['nowpayment'])){
    if(isset($_GET['NP_id'])){
        $hash_id = $_GET['NP_id'];
        $base_url = 'https://api.nowpayments.io/v1/payment/' . $hash_id;
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . $nowPaymentKey]);
        curl_setopt($ch, CURLOPT_URL, $base_url);
        $res = json_decode(curl_exec($ch));
        $hash_id = $res->invoice_id;
    
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ? AND `state` = 'pending'");
        $stmt->bind_param("i", $hash_id);
        $stmt->execute();
        $payInfo = $stmt->get_result();
        $stmt->close();
        
        if(mysqli_num_rows($payInfo)==0){
            showForm("کد پرداخت یافت نشد","خطا!");
        }else{
            $payParam = $payInfo->fetch_assoc();
            $rowId = $payParam['id'];
            $amount = $payParam['price'];
            $user_id = $payParam['user_id'];
            $payType = $payParam['type'];
        
            $plan_id = $payParam['plan_id'];
            $volume = $payParam['volume'];
            $day = $payParam['day'];
            if($payType == "BUY_SUB") $payDescription = "خرید اشتراک";
            elseif($payType == "RENEW_ACCOUNT") $payDescription = "تمدید اکانت";
            elseif($payType == "INCREASE_WALLET") $payDescription ="شارژ کیف پول";
            elseif(preg_match('/^INCREASE_DAY_(\d+)_(\d+)_(.+)_(\d+)/',$payType)) $payDescription = "افزایش زمان اکانت";
            elseif(preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)_(.+)_(\d+)/',$payType)) $payDescription = "افزایش حجم اکانت";    
        
            //==============================================================
            if($res->payment_status == 'finished' or $res->payment_status == 'confirmed' or $res->payment_status == 'sending'){
                doAction($rowId, "nowpayment");
            } else {
                if($res->payment_status == 'partially_paid'){
                    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_payment' WHERE `payid` =?");
                    $stmt->bind_param("i", $hash_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    showForm("#$hash_id - شما هزینه کمتری واریز کردید، لطفا به پشتیبانی مراجعه کنید",$payDescription);
                }else{
                    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'canceled' WHERE `payid` =?");
                    $stmt->bind_param("i", $hash_id);
                    $stmt->execute();
                    $stmt->close();

                    showForm("پرداخت انجام نشد",$payDescription);
                }
            }
        }
    }
    else{
        showForm("پرداخت انجام نشد","خطا!");
    }
}elseif(isset($_GET['zarinpal'])){
    $hash_id = $_GET['hash_id'];
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND `state` = 'pending'");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    if(mysqli_num_rows($payInfo)==0){
        showForm("کد پرداخت یافت نشد","خطا!");
    }else{
        $payParam = $payInfo->fetch_assoc();
        $rowId = $payParam['id'];
        $amount = $payParam['price'];
        $user_id = $payParam['user_id'];
        $payType = $payParam['type'];
    

        $Authority = $_GET['Authority'];
        //==============================================================
        $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']);
        $result = $client->PaymentVerification([
        'MerchantID' => $zarinpalId,
        'Authority' => $Authority,
        'Amount' => $amount,
        ]);
        //==============================================================
        if ($_GET['Status'] == 'OK' and $result->Status == 100){
            doAction($rowId, "zarinpal");
        }else{
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'canceled' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();
            
            showForm("پرداخت شما انجام نشد!","درگاه زرین پال");
        }
    }
}else{
    showForm("درگاه پرداخت شناسایی نشد","خطا!");
    exit();
}

function doAction($payRowId, $gateType){
    global $connection, $admin, $botUrl, $mainKeys;
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ? AND `state` = 'pending'");
    $stmt->bind_param("i", $payRowId);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    $payParam = $payInfo->fetch_assoc();
    $rowId = $payParam['id'];
    $amount = $payParam['price'];
    $user_id = $payParam['user_id'];
    $payType = $payParam['type'];
    $from_id = $user_id; 
    
    $plan_id = $payParam['plan_id'];
    $volume = $payParam['volume'];
    $day = $payParam['day'];
    if($payType == "BUY_SUB") $payDescription = "خرید اشتراک";
    elseif($payType == "RENEW_ACCOUNT") $payDescription = "تمدید اکانت";
    elseif($payType == "INCREASE_WALLET") $payDescription ="شارژ کیف پول";
    elseif(preg_match('/^INCREASE_DAY_(\d+)_(\d+)_(.+)_(\d+)/',$payType)) $payDescription = "افزایش زمان اکانت";
    elseif(preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)_(.+)_(\d+)/',$payType)) $payDescription = "افزایش حجم اکانت";    

    if($gateType == "zarinpal") $payDescription = "خرید اشتراک";
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid' WHERE `id` =?");
    $stmt->bind_param("i", $payRowId);
    $stmt->execute();
    $stmt->close();

    if($payType == "BUY_SUB"){
        $user_id = $user_id;
        $fid = $plan_id;
        $acctxt = '';
        
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $userinfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $file_detail = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        $days = $file_detail['days'];
        $date = time();
        $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
        $expire_date = $date + (86400 * $days);
        $type = $file_detail['type'];
        $volume = $file_detail['volume'];
        $protocol = $file_detail['protocol'];
        $amount = $file_detail['price'];
        
        $server_id = $file_detail['server_id'];
        $netType = $file_detail['type'];
        $acount = $file_detail['acount'];
        $inbound_id = $file_detail['inbound_id'];
        $limitip = $file_detail['limitip'];
    
    
        if($acount == 0 and $inbound_id != 0){
            showForm('پرداخت شما انجام شد ولی ظرفیت این کانکشن پر شده است، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد",$payDescription, false);
            
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $amount, $user_id);
            $stmt->execute();
            $stmt->close();
            sendMessage("✅ مبلغ " . number_format($amount). " تومان به حساب شما اضافه شد",null,null,$user_id);
            sendMessage("✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $user_id مورد نظر اضافه شد",null,null,$admin);                

            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] != 0) {
                $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
                $stmt->bind_param("i", $server_id);
                $stmt->execute();
                $stmt->close();
    
            } else {
                showForm('پرداخت شما انجام شد ولی ظرفیت این سرور پر شده است، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد",$payDescription, false);
                
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $amount, $user_id);
                $stmt->execute();
                $stmt->close();
                sendMessage("✅ مبلغ " . number_format($amount). " تومان به حساب شما اضافه شد",null,null,$user_id);
                sendMessage("✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $user_id مورد نظر اضافه شد",null,null,$admin);                
                exit;
            }
        }else{
            if($acount != 0) {
                $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - 1 WHERE id=?");
                $stmt->bind_param("i", $fid);
                $stmt->execute();
                $stmt->close();
            }
        }
    
        $uniqid = generateRandomString(42,$protocol); 
    
        $savedinfo = file_get_contents('../temp.txt');
        $savedinfo = explode('-',$savedinfo);
        $port = $savedinfo[0] + 1;
        $last_num = $savedinfo[1] + 1;
    
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $srv_remark = $stmt->get_result()->fetch_assoc()['remark'];
        $stmt->close();
    
        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $portType = $stmt->get_result()->fetch_assoc()['port_type'];
        $stmt->close();

        if($portType == "auto"){
            $port++;
        }else{
            $port = rand(1111,65000);
        }

        // $remark = "{$srv_remark}-{$last_num}";
        $rnd = RandomString(4);
        $remark = "{$srv_remark}-{$user_id}-{$rnd}";

        file_put_contents('../temp.txt',$port.'-'.$last_num);
        
        if($inbound_id == 0){    
            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType); 
            if(! $response->success){
                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType);
            } 
        }else {
            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip); 
            if(! $response->success){
                $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip);
            } 
        }
        
        if(is_null($response)){
            showForm('پرداخت شما با موفقیت انجام شد ولی گلم ، اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ...مبلغ ' . number_format($amount) ." به کیف پولت اضافه شد",$payDescription);
            
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $amount, $user_id);
            $stmt->execute();
            $stmt->close();
            sendMessage("✅ مبلغ " . number_format($amount). " تومان به حساب شما اضافه شد",null,null,$user_id);
            sendMessage("✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $user_id مورد نظر اضافه شد",null,null,$admin);                

            exit;
        }
    	if($response == "inbound not Found"){
            showForm("پرداخت شما با موفقیت انجام شد ولی ❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...مبلغ " . number_format($amount) . " به کیف پول شما اضافه شد",$payDescription);

            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $amount, $user_id);
            $stmt->execute();
            $stmt->close();
            sendMessage("✅ مبلغ " . number_format($amount). " تومان به حساب شما اضافه شد",null,null,$user_id);
            sendMessage("✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $user_id مورد نظر اضافه شد",null,null,$admin);                

    		exit;
    	}
    	if(!$response->success){
            showForm('پرداخت شما با موفقیت انجام شد ولی خطا داد لطفا سریع به مدیر بگو ... مبلغ '. number_format($amount) . " تومان به کیف پولت اضافه شد",$payDescription);

            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $amount, $user_id);
            $stmt->execute();
            $stmt->close();
            sendMessage("✅ مبلغ " . number_format($amount). " تومان به حساب شما اضافه شد",null,null,$user_id);
            sendMessage("✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $user_id مورد نظر اضافه شد",null,null,$admin);                

            exit;
        }
        showForm('پرداخت شما با موفقیت انجام شد 🚀 | 😍 در حال ارسال کانفیگ به تلگرام شما ...',$payDescription, true);
        
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $amount, $user_id);
        $stmt->execute();
        include '../phpqrcode/qrlib.php';
        $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id);
        foreach($vraylink as $vray_link){
$acc_text = "

😍 سفارش جدید شما
📡 پروتکل: $protocol
🎴 پورت: $port
💰 قیمت: $amount تومان
🔮 نام سرویس: $remark

🔗 <code> $vray_link </code> 

\n
";
        
            $file = RandomString() .".png";
            $ecc = 'L';
            $pixel_Size = 10;
            $frame_Size = 10;
            
            QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
        	addBorderImage($file);
        	sendPhoto($botUrl . "pay/" . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>"صفحه اصلی 🏘",'callback_data'=>"mainMenu"]]]]),"HTML", $user_id);
            unlink($file);
        }
        $vray_link = json_encode($vraylink);
        $date = time();
    	$stmt = $connection->prepare("INSERT INTO `orders_list` VALUES (NULL,  ?, '', ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0);");
        $stmt->bind_param("siiissisii", $user_id, $fid, $server_id, $inbound_id, $remark, $protocol, $expire_date, $vray_link, $amount, $date);
        $stmt->execute();
        $order = $stmt->get_result(); 
        $stmt->close();
        
        $user_info = Bot('getChat',['chat_id'=>$user_id])->result;
        $first_name = $user_info->first_name;
        $username = $user_info->username;
        
        sendMessage("خرید اکانت جدید با درگاه $gateType\n\nآیدی کاربر: $user_id\nاسم کاربر: <a href='tg://user?id=$user_id'>$first_name</a>\nیوزرنیم: @$username\nمبلغ پرداختی: $amount\n",null,"html", $admin);
    }
    elseif($payType == "INCREASE_WALLET"){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $amount, $user_id);
        $stmt->execute();
        $stmt->close();
        showForm("پرداخت شما با موفقیت انجام شد، مبلغ ". number_format($amount) . " تومان به کیف پول شما اضافه شد",$payDescription, true);
        sendMessage("✅ مبلغ " . number_format($amount). " تومان به حساب شما اضافه شد",null,null,$user_id);
        sendMessage("✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $user_id مورد نظر اضافه شد",null,null,$admin);                
    }
    elseif($payType == "RENEW_ACCOUNT"){
        $oid = $plan_id;
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $fid = $order['fileid'];
        $remark = $order['remark'];
        $server_id = $order['server_id'];
        $inbound_id = $order['inbound_id'];
        $expire_date = $order['expire_date'];
        $expire_date = ($expire_date > $time) ? $expire_date : $time;
        
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $respd = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $name = $respd['title'];
        $days = $respd['days'];
        $volume = $respd['volume'];
        $amount = $respd['price'];
    
    
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $userwallet = $stmt->get_result()->fetch_assoc()['wallet'];
        $stmt->close();
    
        if($inbound_id > 0)
            $response = editClientTraffic($server_id, $inbound_id, $remark, $volume, $days);
        else
            $response = editInboundTraffic($server_id, $remark, $volume, $days);
    
    	if(is_null($response)){
    		showForm('پرداخت شما با موفقیت انجام شد ولی مشکل فنی در اتصال به سرور. لطفا به مدیریت اطلاع بدید، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد",$payDescription);
    		
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $amount, $user_id);
            $stmt->execute();
            $stmt->close();
            sendMessage("✅ مبلغ " . number_format($amount). " تومان به حساب شما اضافه شد",null,null,$user_id);
            sendMessage("✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $user_id مورد نظر اضافه شد",null,null,$admin);
    		exit;
    	}
    	$stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
    	$newExpire = $expire_date + $days * 86400;
    	$stmt->bind_param("ii", $newExpire, $oid);
    	$stmt->execute();
    	$stmt->close();
/*    	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    	$stmt->bind_param("iiisii", $user_id, $server_id, $inbound_id, $remark, $amount, $time);
    	$stmt->execute();
    	$stmt->close();
*/    	
        showForm("✅سرویس $remark با موفقیت تمدید شد",$payDescription, true);
        exit;

    }
    elseif(preg_match('/^INCREASE_DAY_(\d+)_(\d+)_(.+)_(\d+)/',$payType,$match)){
        $server_id = $match[1];
        $inbound_id = $match[2];
        $remark = $match[3];
        $planid = $match[4];
    
        
        $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $amount = $res['price'];
        $volume = $res['volume'];
        
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $userwallet = $stmt->get_result()->fetch_assoc()['wallet'];
        $stmt->close();
        
    
        if($inbound_id > 0)
            $response = editClientTraffic($server_id, $inbound_id, $remark, 0, $volume);
        else
            $response = editInboundTraffic($server_id, $remark, 0, $volume);
            
        if($response->success){
            $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `remark` = ?");
            $newVolume = $volume * 86400;
            $stmt->bind_param("is", $newVolume, $remark);
            $stmt->execute();
            $stmt->close();
            
            $time = time();
/*            $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
            $newVolume = $volume * 86400;
            $stmt->bind_param("iiisii", $user_id, $server_id, $inbound_id, $remark, $amount, $time);
            $stmt->execute();
            $stmt->close();
*/            
            showForm("پرداخت شما با موفقیت انجام شد. $volume روز به مدت زمان سرویس شما اضافه شد",$payDescription, true);
            exit;
        }else {
            showForm("پرداخت شما با موفقیت انجام شد ولی به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5دقیقه دیگر دوباره تست کنید مبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد", $payDescription, true);
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $amount, $user_id);
            $stmt->execute();
            $stmt->close();
            sendMessage("✅ مبلغ " . number_format($amount). " تومان به حساب شما اضافه شد",null,null,$user_id);
            sendMessage("✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $user_id مورد نظر اضافه شد",null,null,$admin);
            exit;
        }
    }
    elseif(preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)_(.+)_(\d+)/',$payType, $match)){
        $server_id = $match[1];
        $inbound_id = $match[2];
        $remark = $match[3];
        $planid = $match[4];
        $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
        $stmt->bind_param("i",$planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $amount = $res['price'];
        $volume = $res['volume'];
    
        $acctxt = '';

        
        if($inbound_id > 0)
            $response = editClientTraffic($server_id, $inbound_id, $remark, $volume, 0);
        else
            $response = editInboundTraffic($server_id, $remark, $volume, 0);
        if($response->success){
            $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `remark` = ?");
            $stmt->bind_param("s", $remark);
            $stmt->execute();
            $stmt->close();
            showForm("پرداخت شما با موفقیت انجام شد. $volume گیگ به حجم سرویس شما اضافه شد",$payDescription, true);
        }else {
            showForm("پرداخت شما با موفقیت انجام شد ولی مشکل فنی در ارتباط با سرور. لطفا سلامت سرور را بررسی کنید مبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد",$payDescription, true);
            
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $amount, $user_id);
            $stmt->execute();
            $stmt->close();
            sendMessage("✅ مبلغ " . number_format($amount). " تومان به حساب شما اضافه شد",null,null,$user_id);
            sendMessage("✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $user_id مورد نظر اضافه شد",null,null,$admin);                

            exit;
        }
    }
    
    sendMessage("✅ پرداخت شما با موفقیت انجام شد",json_encode(['inline_keyboard'=>[[['text'=>"صفحه اصلی 🏘",'callback_data'=>"mainMenu"]]]]),null,$user_id);
}

function showForm($msg, $type = "", $state = false){
    ?>
        <html dir="rtl">
        <head>
            <script>
          (function(w,d,s,l,i){w[l]=w[l]||[];
            w[l].push({'gtm.start': new Date().getTime(),event:'gtm.js', });
            var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
            j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl+'&gtm_auth=&gtm_preview=&gtm_cookies_win=x';
            f.parentNode.insertBefore(j,f);
          })(window,document,'script','dataLayer','GTM-MSN6P6G');</script>
          <meta charset="utf-8"><meta name="viewport" content="width=device-width">
    		<title><?php echo $type;?></title>
            <meta name="next-head-count" content="4">
            <link rel="preload" href="../install/css/20bb620751bbea45.css" as="style">
            <link rel="stylesheet" href="../install/css/20bb620751bbea45.css" data-n-g="">
            <noscript data-n-css=""></noscript>
        </head>
        <body>
            <noscript>
                <iframe src="https://www.googletagmanager.com/ns.html?id=GTM-MSN6P6G&gtm_auth=&gtm_preview=&gtm_cookies_win=x"
                height="0" width="0" style="display:none;visibility:hidden" id="tag-manager"></iframe>
            </noscript>
            <div id="__next">
                <section class="ant-layout ant-layout-rtl PayPing-layout background--primary justify-center" style="min-height:100vh">
                    <header class="ant-layout-header PayPing-header-logo justify-center align-center"></header>
                    <main class="ant-layout-content justify-center align-center flex-column">
                        <div class="ant-row ant-row-center ant-row-rtl PayPing-row w-100">
                            <div class="ant-col PayPing-col PayPing-error-card ant-col-xs-23 ant-col-rtl ant-col-sm-20 ant-col-md-16 ant-col-lg-12 ant-col-xl-8 ant-col-xxl-6">
                                <div class="py-2 align-center color--danger flex-column">
                                    <?php if(!$state){ ?><svg fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" class="PayPing-icon" stroke-width="1" width="100">
                                        <circle cx="12" cy="12" r="11"></circle>
                                        <path d="M15.3 8.7l-6.6 6.6M8.7 8.7l6.6 6.6"></path>
                                    </svg>
                                    <?php }?>
                                    <div class="py-2"><?php echo $msg;?></div>
                                </div>
                            </div>
                        </div>
                    </main>
                    <footer class="ant-layout-footer PayPing-footer">
                        <div class="ant-row ant-row-center ant-row-rtl PayPing-row w-100">
                            <div class="ant-col PayPing-col PayPing-footer-links ant-col-xs-23 ant-col-rtl ant-col-sm-22 ant-col-md-20 ant-col-lg-18 ant-col-xl-14 ant-col-xxl-10"></div>
                        </div>
                    </footer>
                </section>
            </div>
        </body>
    </html>
    <?php
}
?>