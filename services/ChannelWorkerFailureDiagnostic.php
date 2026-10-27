<?php
declare(strict_types=1);

/** Fixed, value-free projection for the ordinary scheduler's existing catch. */
final class ChannelWorkerFailureDiagnostic
{
    public static function classify(Throwable $error): array
    {
        if ($error instanceof PDOException) {
            $code=(string)$error->getCode();
            $state=preg_match('/\A[A-Z0-9]{5}\z/D',$code)===1?$code:null;
            return ['category'=>'database_exception','sqlstate'=>$state];
        }
        $category=match($error->getMessage()) {
            'channel_stage_capture_failed'=>'stage_capture_failed',
            'metrika_config_missing'=>'metrika_config_unavailable',
            'tracker_transport_failed'=>'tracker_transport_failed',
            'tracker_response_invalid'=>'tracker_response_invalid',
            default=>$error instanceof Error?'php_error':'runtime_exception',
        };
        return ['category'=>$category,'sqlstate'=>null];
    }
}
