<?php
/**
 * Created by PhpStorm.
 * User: nonoca
 * Date: 2017/03/19
 * Time: 23:42
 */

namespace App\Http\Controllers;


use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use LINE\LINEBot\Constant\HTTPHeader;
use LINE\LINEBot\Event\MessageEvent;
use LINE\LINEBot\Event\MessageEvent\TextMessage;
use LINE\LINEBot\Exception\InvalidEventRequestException;
use LINE\LINEBot\Exception\InvalidSignatureException;
use LINE\LINEBot\Exception\UnknownEventTypeException;
use LINE\LINEBot\Exception\UnknownMessageTypeException;
use Predis\Client;
use LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use LINE\LINEBot\MessageBuilder\ImageMessageBuilder;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;


class CallbackController extends Controller
{
    public function index(Request $req){
        //$data = $req->all();

        $secret = env('LINE_CHANNEL_SECRET');
        $token = env('LINE_CHANNEL_ACCESS_TOKEN');

        $bot = new LINEBot(new CurlHTTPClient($token), ['channelSecret' => $secret]);

        $signature = $req->header(HTTPHeader::LINE_SIGNATURE);
        if (empty($signature)) {
            return response()->json(['message' => 'Bad Request'],400);
        }

        try {
            $events = $bot->parseEventRequest($req->getContent(), $signature);
        } catch (InvalidSignatureException $e) {
            return response()->json(['message' => 'Invalid signature'],400);
        } catch (UnknownEventTypeException $e) {
            return response()->json(['message' => 'Unknown event type has come'],400);
        } catch (UnknownMessageTypeException $e) {
            return response()->json(['message' => 'Unknown message type has come'],400);
        } catch (InvalidEventRequestException $e) {
            return response()->json(['message' => 'Invalid event request'],400);
        }

        foreach ($events as $event) {

            // USER info
            $user_id = $event->getUserId();
            $profileData = $bot->getProfile($user_id);
            if ($profileData->isSucceeded()) {
                $profile = $profileData->getJSONDecodedBody();
            }
            if($event->getType() == 'beacon'){
                // got beaconevent
                if($event->getBeaconEventType() == 'enter') {
                    $msg = new MultiMessageBuilder();
                    $msg->add(new TextMessageBuilder('beacon により'.$profile['displayName'].'さんが近くに居ることを通知しました。'));
                    $msg->add(new ImageMessageBuilder('https://goo.gl/FgHe12', 'https://goo.gl/FgHe12'));
                    $this->pushDiscord($profile['displayName'].'さんが来ました。', $profile['pictureUrl']);
                } else if($event->getBeaconEventType() == 'leave') {
                    $msg = new MultiMessageBuilder();
                    $msg->add(new TextMessageBuilder('beacon により'.$profile['displayName'].'さんが遠ざかったことを通知しました。'));
                    $msg->add(new ImageMessageBuilder('https://goo.gl/aLVqkI', 'https://goo.gl/aLVqkI'));
                } else {
                    $msg = new MultiMessageBuilder();
                    $msg->add(new TextMessageBuilder('beacon!'));
                }

                $resp = $bot->replyMessage($event->getReplyToken(), $msg);
            }else {
                if (!($event instanceof MessageEvent)) {
                    Log::info('Non message event has come');
                    continue;
                }
                if (!($event instanceof TextMessage)) {
                    Log::info('Non text message has come');
                    continue;
                }

                // get Text
                $replyText = $this->docomo_talk($event->getText(), $user_id);

                Log::info('Reply text: ' . $replyText);
                $resp = $bot->replyText($event->getReplyToken(), $replyText);

                Log::info($resp->getHTTPStatus() . ': ' . $resp->getRawBody());
            }
        }

        return response()->json([], 200);
    }

    private function docomo_talk($send_message, $id) {
        // docomo chatAPI
        $api_key = env('DOCOMO_API_KEY');;
        $api_url = 'https://api.apigw.smt.docomo.ne.jp/dialogue/v1/dialogue?APIKEY='.$api_key;


        // conversation Log
        $redis = new Client('tcp://'.env('REDIS_URL').':'.env('REDIS_PORT'));
        if($redis->exists($id))
            $context = $redis->get($id);
        else
            $context = "";

        // chat framework
        $req_body = Array();
        $req_body['utt'] = $send_message;
        $req_body['context'] = $context;
        $req_body['t'] = 0;

        $headers = array(
            'Content-Type: application/json; charset=UTF-8',
        );
        $options =  json_encode($req_body);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $api_url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $options);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // 証明書の検証を行わない
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $res = json_decode(curl_exec($curl));

        if(!empty($res)){
            $redis->set($id, $res->context);
            $redis->expire($id, 120);
        }


        return $res->utt;
    }

    private function pushDiscord($msg, $profile_img){
        // Discord Webhook
        $hook_url = env('DISCORD_WEBHOOK');

        $options = [];
        $embed = [];
        $thumbnail = [];

        $thumbnail['url'] = $profile_img.'.png';

        $embed[0]['title'] = 'incoming Event!';
        $embed[0]['description'] = $msg;
        $embed[0]['url'] = 'https://www.google.com';
        $embed[0]['thumbnail'] = $thumbnail;
        $embed[0]['type'] = 'rich';
        $embed[0]['color'] = hexdec('7289DA');

        $options['embeds'] = $embed;
        $options['username'] = 'Miuna Shiodome';

        $req = json_encode($options);

        Log::info($req);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $hook_url);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $req);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // 証明書の検証を行わない
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $res = json_decode(curl_exec($curl));
    }

}