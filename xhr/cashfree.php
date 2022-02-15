<?php
if (IS_LOGGED == false) {
	header("Location: $site_url");
	exit();
}
$types = array(
    'buy_album',
    'buy_song',
    'go_pro',
    'wallet',
);
if ($option == 'initialize') {
	if (!empty($_POST['type']) && in_array($_POST['type'], $types) && !empty($_POST['phone']) && !empty($_POST['name']) && !empty($_POST['email']) && filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
		$type = secure($_POST['type']);
		$price    = 0;
		if ($type == 'buy_album') {
			if (!empty($_POST['id'])) {
				$album = $db->where('album_id',secure($_POST['id']))->getOne(T_ALBUMS);
				if (!empty($album) && !empty($album->price) && is_numeric($album->price) && $album->price > 0) {
					$price    = $album->price;
					$callback_url = $music->config->site_url . "/endpoints/cashfree/pay?type=buy_album&id=".secure($_POST['id']);
				}
				else{
					$data['status'] = 400;
					$data['error'] = lang("Please check your details");
				}
			}
			else{
				$data['status'] = 400;
				$data['error'] = lang("Please check your details");
			}
		}
		elseif ($type == 'buy_song') {
			if (!empty($_POST['id'])) {
				$trackID = secure($_POST['id']);
				$getIDAudio = $db->where('audio_id', $trackID)->getValue(T_SONGS, 'id');
				if (empty($getIDAudio)) {
				    $data = array(
				        'status' => 400,
				        'error' => 'invalid track'
				    );
				}
				if (isTrackPurchased($getIDAudio)) {
				    $data['status'] = 400;
					$data['error'] = 'You already purchase this track.';
				}
				$songData = songData($getIDAudio);

				if (empty($songData->price)) {
				    $data = array(
				        'status' => 400,
				        'error' => 'no price.'
				    );
				}
				$price    = $songData->price;
				$callback_url = $music->config->site_url . "/endpoints/cashfree/pay?type=buy_song&id=".$trackID;
			}
			else{
				$data['status'] = 400;
				$data['error'] = lang("Please check your details");
			}
		}
		elseif ($type == 'go_pro') {
			$price = $music->config->pro_price;
			$callback_url = $music->config->site_url . "/endpoints/cashfree/pay?type=go_pro";
		}
		elseif ($type == 'wallet') {
			if (!empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0) {
				$price = secure($_POST['amount']);
				$callback_url = $music->config->site_url . "/endpoints/cashfree/pay?type=wallet&amount=".$price;
			}
			else{
				$data['status'] = 400;
				$data['error'] = lang("Please check your details");
			}
		}

		if (!empty($price) && empty($data['error'])) {
			$result = array();
		    $order_id = uniqid();
		    $name = secure($_POST['name']);
		    $email = secure($_POST['email']);
		    $phone = secure($_POST['phone']);


		    $secretKey = $music->config->cashfree_secret_key;
			$postData = array( 
			  "appId" => $music->config->cashfree_client_key, 
			  "orderId" => "order".$order_id, 
			  "orderAmount" => $price, 
			  "orderCurrency" => "INR", 
			  "orderNote" => "", 
			  "customerName" => $name, 
			  "customerPhone" => $phone, 
			  "customerEmail" => $email,
			  "returnUrl" => $callback_url, 
			  "notifyUrl" => $callback_url,
			);
			 // get secret key from your config
			 ksort($postData);
			 $signatureData = "";
			 foreach ($postData as $key => $value){
			      $signatureData .= $key.$value;
			 }
			 $signature = hash_hmac('sha256', $signatureData, $secretKey,true);
			 $signature = base64_encode($signature);
			 $cashfree_link = 'https://test.cashfree.com/billpay/checkout/post/submit';
			 if ($music->config->cashfree_mode == 'live') {
			 	$cashfree_link = 'https://www.cashfree.com/checkout/post/submit';
			 }

			$form = '<form id="redirectForm" method="post" action="'.$cashfree_link.'"><input type="hidden" name="appId" value="'.$music->config->cashfree_client_key.'"/><input type="hidden" name="orderId" value="order'.$order_id.'"/><input type="hidden" name="orderAmount" value="'.$price.'"/><input type="hidden" name="orderCurrency" value="INR"/><input type="hidden" name="orderNote" value=""/><input type="hidden" name="customerName" value="'.$name.'"/><input type="hidden" name="customerEmail" value="'.$email.'"/><input type="hidden" name="customerPhone" value="'.$phone.'"/><input type="hidden" name="returnUrl" value="'.$callback_url.'"/><input type="hidden" name="notifyUrl" value="'.$callback_url.'"/><input type="hidden" name="signature" value="'.$signature.'"/></form>';
			$data['status'] = 200;
			$data['html'] = $form;
		}
	}
}
if ($option == 'pay') {
	if (empty($_POST['txStatus']) || $_POST['txStatus'] != 'SUCCESS') {
		header("Location: $site_url");
		exit();
	}

	if (!empty($_GET['type']) && in_array($_GET['type'], $types)) {
		$orderId = $_POST["orderId"];
		$orderAmount = $_POST["orderAmount"];
		$referenceId = $_POST["referenceId"];
		$txStatus = $_POST["txStatus"];
		$paymentMode = $_POST["paymentMode"];
		$txMsg = $_POST["txMsg"];
		$txTime = $_POST["txTime"];
		$signature = $_POST["signature"];
		$data = $orderId.$orderAmount.$referenceId.$txStatus.$paymentMode.$txMsg.$txTime;
		$hash_hmac = hash_hmac('sha256', $data, $music->config->cashfree_secret_key, true) ;
		$computedSignature = base64_encode($hash_hmac);
		if ($signature == $computedSignature) {
			$type = secure($_GET['type']);
			if ($type == 'buy_album') {
				if (!empty($_GET['id'])) {
					$album = $db->where('album_id',secure($_GET['id']))->getOne(T_ALBUMS);
					$albumData = albumData($album->id, true, true, true);
					if (!empty($albumData) && !empty($albumData->price) && is_numeric($albumData->price) && $albumData->price > 0) {
						$album_id = $albumData->album_id;

						$getAdminCommission = $music->config->commission;
				        $final_price = 0;

				        $createPayment = false;
				        foreach ($albumData->songs as $key => $song){
				            $final_price += round((($getAdminCommission * $song->price) / 100), 2);
				            $addPurchase = [
				                'track_id' => $song->id,
				                'user_id' => $user->id,
				                'price' => $song->price,
				                'track_owner_id' => $song->user_id,
				                'final_price' => round((($getAdminCommission * $song->price) / 100), 2),
				                'commission' => $getAdminCommission,
				                'time' => time()
				            ];

				            $createPayment = $db->insert(T_PURCHAES, $addPurchase);
				            if ($createPayment) {
				                CreatePayment(array(
				                    'user_id'   => $user->id,
				                    'amount'    => $final_price,
				                    'type'      => 'TRACK',
				                    'pro_plan'  => 0,
				                    'info'      => $song->audio_id,
				                    'via'       => 'Cashfree'
				                ));
				                $create_notification = createNotification([
				                    'notifier_id' => $user->id,
				                    'recipient_id' => $song->user_id,
				                    'type' => 'purchased',
				                    'track_id' => $song->id,
				                    'url' => "track/$song->audio_id"
				                ]);
				            }
				        }

				        if ($createPayment) {
				            $updatealbumpurchases = $db->where('album_id', $album_id)->update(T_ALBUMS, array('purchases' => $db->inc(1) ));
				            $addUserWallet = $db->where('id', $albumData->user_id)->update(T_USERS, ['balance' => $db->inc($final_price)]);
				            header("Location: $site_url/album/{$album_id}");
							exit();
				    	} else {
				    		header("Location: $site_url");
							exit();
				    	}
					}
					else{
						header("Location: $site_url");
						exit();
					}
				}
				else{
					header("Location: $site_url");
					exit();
				}
			}
			elseif ($type == 'buy_song') {
				if (!empty($_GET['id'])) {
					$trackID = secure($_GET['id']);
					$getIDAudio = $db->where('audio_id', $trackID)->getValue(T_SONGS, 'id');
					if (empty($getIDAudio)) {
					    header("Location: $site_url/payment-error?reason=not-found");
						exit();
					}
					if (isTrackPurchased($getIDAudio)) {
					    header("Location: $site_url/payment-error?reason=purchased");
						exit();
					}
					$songData = songData($getIDAudio);

					if (empty($songData->price)) {
					    header("Location: $site_url/payment-error?reason=no-price");
						exit();
					}
					$getAdminCommission = $music->config->commission;
			        $final_price = round((($getAdminCommission * $songData->price) / 100), 2);
			        $addPurchase = [
			            'track_id' => $songData->id,
			            'user_id' => $user->id,
			            'price' => $songData->price,
			            'track_owner_id' => $songData->user_id,
			            'final_price' => $final_price,
			            'commission' => $getAdminCommission,
			            'time' => time()
			        ];
			        $createPayment = $db->insert(T_PURCHAES, $addPurchase);
			        if ($createPayment) {
			            CreatePayment(array(
			                'user_id'   => $user->id,
			                'amount'    => $final_price,
			                'type'      => 'TRACK',
			                'pro_plan'  => 0,
			                'info'      => $songData->audio_id,
			                'via'       => 'Cashfree'
			            ));
			            $addUserWallet = $db->where('id', $songData->user_id)->update(T_USERS, ['balance' => $db->inc($final_price)]);
			            $create_notification = createNotification([
			                'notifier_id' => $user->id,
			                'recipient_id' => $songData->user_id,
			                'type' => 'purchased',
			                'track_id' => $songData->id,
			                'url' => "track/$songData->audio_id"
			            ]);
			            header("Location: $site_url/track/{$songData->audio_id}");
						exit();
			        } else {
			            header("Location: $site_url");
						exit();
			        }
				}
				else{
					header("Location: $site_url");
					exit();
				}
			}
			elseif ($type == 'go_pro') {
				$updateUser = $db->where('id', $user->id)->update(T_USERS, ['is_pro' => 1, 'pro_time' => time()]);
			    if ($updateUser) {
			        CreatePayment(array(
			            'user_id'   => $user->id,
			            'amount'    => $music->config->pro_price,
			            'type'      => 'PRO',
			            'pro_plan'  => 1,
			            'info'      => '',
			            'via'       => 'Cashfree'
			        ));
			        if ((!empty($_SESSION['ref']) || !empty($user->ref_user_id)) && $music->config->affiliate_type == 1 && $user->referrer == 0) {
			            if ($music->config->amount_percent_ref > 0) {
			                if (!empty($_SESSION['ref'])) {
			                    $ref_user_id = $db->where('username', secure($_SESSION['ref']))->getValue(T_USERS, 'id');
			                }
			                elseif (!empty($user->ref_user_id)) {
			                    $ref_user_id = $db->where('id', $user->ref_user_id)->getValue(T_USERS, 'id');
			                }
			                if (!empty($ref_user_id) && is_numeric($ref_user_id)) {
			                    $db->where('id', $user->user_id)->update(T_USERS,array(
			                        'referrer' => $ref_user_id,
			                        'src' => 'Referrer'
			                    ));
			                    $ref_amount     = ($music->config->amount_percent_ref * $music->config->pro_price) / 100;
			                    $db->where('id', $ref_user_id)->update(T_USERS,array('balance' => $db->inc($ref_amount)));
			                    unset($_SESSION['ref']);
			                }
			            } else if ($music->config->amount_ref > 0) {
			                if (!empty($_SESSION['ref'])) {
			                    $ref_user_id = $db->where('username', secure($_SESSION['ref']))->getValue(T_USERS, 'id');
			                }
			                elseif (!empty($user->ref_user_id)) {
			                    $ref_user_id = $db->where('id', $user->ref_user_id)->getValue(T_USERS, 'id');
			                }
			                if (!empty($ref_user_id) && is_numeric($ref_user_id)) {
			                    $db->where('id', $user->user_id)->update(T_USERS,array(
			                        'referrer' => $ref_user_id,
			                        'src' => 'Referrer'
			                    ));
			                    $db->where('id', $ref_user_id)->update(T_USERS,array('balance' => $db->inc($music->config->amount_ref)));
			                    unset($_SESSION['ref']);
			                }
			            }
			        }
			        header("Location: $site_url/upgraded");
			        exit();
			    } else {
			        header("Location: $site_url/payment-error?reason=cant-create-payment");
			        exit();
			    }
			}
			elseif ($type == 'wallet') {
				$price = secure($_GET['amount']);
			    $updateUser = $db->where('id', $user->id)->update(T_USERS, ['wallet' => $db->inc($price)]);
			    if ($updateUser) {
			        CreatePayment(array(
			            'user_id'   => $user->id,
			            'amount'    => $price,
			            'type'      => 'WALLET',
			            'pro_plan'  => 0,
			            'info'      => 'Replenish My Balance',
			            'via'       => 'Cashfree'
			        ));
			        header("Location: $site_url/ads");
			        exit();
			    } else {
			        header("Location: $site_url/payment-error?reason=cant-create-payment");
			        exit();
			    }
			}
	    }
	    else{
	    	header("Location: $site_url");
			exit();
	    }
	}
	header("Location: $site_url");
    exit();
}