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
            if (!($event instanceof MessageEvent)) {
                Log::info('Non message event has come');
                continue;
            }
            if (!($event instanceof TextMessage)) {
                Log::info('Non text message has come');
                continue;
            }
            $replyText = $event->getText();
            Log::info('Reply text: ' . $replyText);
            $resp = $bot->replyText($event->getReplyToken(), $replyText);
            Log::info($resp->getHTTPStatus() . ': ' . $resp->getRawBody());
        }

        return response()->json([], 200);
    }
}