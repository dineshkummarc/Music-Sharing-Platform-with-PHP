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
    if (!empty($_POST['type']) && in_array($_POST['type'], $types) && !empty($_POST['payment_id']) && !empty($_POST['order_id']) && !empty($_POST['amount']) && !empty($_POST['currency'])) {
        $type = secure($_POST['type']);
        $price    = 0;
        if ($type == 'buy_album') {
            if (!empty($_POST['id'])) {
                $album = $db->where('album_id',secure($_POST['id']))->getOne(T_ALBUMS);
                if (!empty($album) && !empty($album->price) && is_numeric($album->price) && $album->price > 0) {
                    $price    = ((int) $album->price) * 100;
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
            }
            else{
                $data['status'] = 400;
                $data['error'] = lang("Please check your details");
            }
        }
        elseif ($type == 'go_pro') {
            $price = $music->config->pro_price;
        }
        elseif ($type == 'wallet') {
            if (!empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0) {
                $price = secure($_POST['amount']);
            }
            else{
                $data['status'] = 400;
                $data['error'] = lang("Please check your details");
            }
        }
        if (!empty($price) && empty($data['error'])) {
            $payment_id = secure($_POST['payment_id']);
            $currency_code = "INR";
            $check = array(
                'amount' => $price,
                'currency' => $currency_code,
            );
            $json = CheckRazorpayPayment($payment_id,$check);
            if (!empty($json) && empty($json->error_code) && empty($json->error)) {
                $price = ($price / 100);
                if ($type == 'buy_album') {
                    if (!empty($_POST['id'])) {
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
                                        'via'       => 'razorpay'
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
                                $data['status'] = 200;
                                $data['url'] = $site_url."/album/".$album_id;
                            } else {
                                $data['status'] = 400;
                                $data['error'] = lang("Please check your details");
                            }
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
                            $data['status'] = 400;
                            $data['error'] = lang("Please check your details");
                        }
                        if (isTrackPurchased($getIDAudio)) {
                            $data['status'] = 400;
                            $data['error'] = lang("Please check your details");
                        }
                        $songData = songData($getIDAudio);

                        if (empty($songData->price)) {
                            $data['status'] = 400;
                            $data['error'] = lang("Please check your details");
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
                                'via'       => 'razorpay'
                            ));
                            $addUserWallet = $db->where('id', $songData->user_id)->update(T_USERS, ['balance' => $db->inc($final_price)]);
                            $create_notification = createNotification([
                                'notifier_id' => $user->id,
                                'recipient_id' => $songData->user_id,
                                'type' => 'purchased',
                                'track_id' => $songData->id,
                                'url' => "track/$songData->audio_id"
                            ]);
                            $data['status'] = 200;
                            $data['url'] = $site_url."/track/".$songData->audio_id;
                        } else {
                            $data['status'] = 400;
                            $data['error'] = lang("Please check your details");
                        }
                    }
                    else{
                        $data['status'] = 400;
                        $data['error'] = lang("Please check your details");
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
                            'via'       => 'razorpay'
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
                        $data['status'] = 200;
                        $data['url'] = $site_url."/upgraded";
                    } else {
                        $data['status'] = 400;
                        $data['error'] = lang("Please check your details");
                    }
                }
                elseif ($type == 'wallet') {
                    $updateUser = $db->where('id', $user->id)->update(T_USERS, ['wallet' => $db->inc($price)]);
                    if ($updateUser) {
                        CreatePayment(array(
                            'user_id'   => $user->id,
                            'amount'    => $price,
                            'type'      => 'WALLET',
                            'pro_plan'  => 0,
                            'info'      => 'Replenish My Balance',
                            'via'       => 'razorpay'
                        ));
                        $data['status'] = 200;
                        $data['url'] = $site_url."/ads";
                    } else {
                        $data['status'] = 400;
                        $data['error'] = lang("Please check your details");
                    }
                }


            }
            else{
                if (!empty($json->error_description)) {
                    $data['error'] = $json->error_description;
                }
                elseif (!empty($json->error) && !empty($json->error->description)) {
                    $data['error'] = $json->error->description;
                }
                else{
                    $data['error'] = lang("Something went wrong");
                }
                $data['status'] = 400;
            }
        }
    }
    else{
        $data['status'] = 400;
        $data['error'] = lang("Please check your details");
    }
}