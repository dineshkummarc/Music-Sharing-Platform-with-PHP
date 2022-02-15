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
    if (!empty($_POST['type']) && in_array($_POST['type'], $types)) {
        $type = secure($_POST['type']);
        $price    = 0;
        if ($type == 'buy_album') {
            if (!empty($_POST['id'])) {
                $album = $db->where('album_id',secure($_POST['id']))->getOne(T_ALBUMS);
                if (!empty($album) && !empty($album->price) && is_numeric($album->price) && $album->price > 0) {
                    $price    = $album->price;
                    $callback_url = $music->config->site_url . "/endpoints/paysera/pay?type=buy_album&id=".secure($_POST['id']);
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
                $callback_url = $music->config->site_url . "/endpoints/paysera/pay?type=buy_song&id=".$trackID;
            }
            else{
                $data['status'] = 400;
                $data['error'] = lang("Please check your details");
            }
        }
        elseif ($type == 'go_pro') {
            $price = $music->config->pro_price;
            $callback_url = $music->config->site_url . "/endpoints/paysera/pay?type=go_pro";
        }
        elseif ($type == 'wallet') {
            if (!empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0) {
                $price = secure($_POST['amount']);
                $callback_url = $music->config->site_url . "/endpoints/paysera/pay?type=wallet&amount=".$price;
            }
            else{
                $data['status'] = 400;
                $data['error'] = lang("Please check your details");
            }
        }
        if (!empty($price) && empty($data['error'])) {
            $price = intval($price);
            require_once 'assets/import/Paysera.php';

            $request = WebToPay::redirectToPayment(array(
                'projectid'     => $music->config->paysera_project_id,
                'sign_password' => $music->config->paysera_sign_password,
                'orderid'       => rand(111111,999999),
                'amount'        => $price,
                'currency'      => $music->config->currency,
                'country'       => 'LT',
                'accepturl'     => $callback_url,
                'cancelurl'     => $site_url.'/payment-error?reason=not-found',
                'callbackurl'   => $site_url.'/payment-error?reason=not-found',
                'test'          => $music->config->paysera_mode,
            ));
            $data = array('status' => 200,
                          'url' => $request);
        }
    }
}
if ($option == 'pay') {
    if (!empty($_GET['type']) && in_array($_GET['type'], $types)) {
        try {
            require_once 'assets/import/Paysera.php';
            $response = WebToPay::checkResponse($_GET, array(
                'projectid'     => $music->config->paysera_project_id,
                'sign_password' => $music->config->paysera_sign_password,
            ));
     
            // if ($response['test'] !== '0') {
            //     throw new Exception('Testing, real payment was not made');
            // }
            if ($response['type'] !== 'macro') {
                header("Location: $site_url/payment-error?reason=not-found");
                exit();
                //throw new Exception('Only macro payment callbacks are accepted');
            }
            $orderId = $response['orderid'];
            $amount = $response['amount'];
            $currency = $response['currency'];

            if ($currency != $music->config->currency) {
                header("Location: $site_url/payment-error?reason=no-price");
                exit();
            }
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
                                    'via'       => 'paysera'
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
                            'via'       => 'paysera'
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
                        'via'       => 'paysera'
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
                $price = $amount;
                $updateUser = $db->where('id', $user->id)->update(T_USERS, ['wallet' => $db->inc($price)]);
                if ($updateUser) {
                    CreatePayment(array(
                        'user_id'   => $user->id,
                        'amount'    => $price,
                        'type'      => 'WALLET',
                        'pro_plan'  => 0,
                        'info'      => 'Replenish My Balance',
                        'via'       => 'paysera'
                    ));
                    header("Location: $site_url/ads");
                    exit();
                } else {
                    header("Location: $site_url/payment-error?reason=cant-create-payment");
                    exit();
                }
            }
        } catch (Exception $e) {
            header("Location: $site_url/payment-error?reason=something-wrong");
            exit();
        }

    }
}