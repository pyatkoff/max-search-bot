<?php
declare(strict_types=1);

/** One private status execution/read budget, shared by bearer and result authorization. */
final class ModeledConversionStatusLimit extends RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct('forecast_status_limit'); }
}
final class ModeledConversionStatusBudget
{
    public const MAX_BYTES=65536;
    private float $startedAt;
    private $monotonic;
    private int $bytes=0;
    private int $reserved=0;

    public function __construct(?float $startedAt=null,?callable $monotonic=null)
    {
        $this->monotonic=$monotonic??static fn():float=>microtime(true);
        $this->startedAt=$startedAt??($this->monotonic)();
    }
    public function check():void
    {
        $elapsed=($this->monotonic)()-$this->startedAt;
        if($elapsed<0||$elapsed>=5.0)throw new ModeledConversionStatusLimit('time_limit');
        if($this->bytes>self::MAX_BYTES)throw new ModeledConversionStatusLimit('byte_limit');
    }
    public function remainingMilliseconds():int
    {
        $this->check();
        return max(1,(int)floor((5.0-(($this->monotonic)()-$this->startedAt))*1000));
    }
    public function remainingBytes():int{$this->check();return self::MAX_BYTES-$this->bytes-$this->reserved;}
    public function consumeBytes(int $bytes):void
    {
        if($bytes<0)throw new ModeledConversionStatusLimit('byte_limit');
        if($bytes>$this->remainingBytes()){
            // A copied optional result still costs bytes even when it exceeded its slice.
            // Absolute exhaustion remains fatal after the reservation is released.
            if($this->reserved>0)$this->bytes+=$bytes;
            throw new ModeledConversionStatusLimit('byte_limit');
        }
        $this->bytes+=$bytes;$this->check();
    }
    /** Preserve bytes for mandatory authorization while an optional diagnostic runs. */
    public function withReservedBytes(int $bytes,callable $operation):mixed
    {
        if($bytes<0)throw new InvalidArgumentException('forecast_status_reserve');
        if($bytes>$this->remainingBytes())throw new ModeledConversionStatusLimit('byte_limit');
        $this->reserved+=$bytes;
        try{return $operation();}finally{$this->reserved-=$bytes;}
    }
    public function prepare(PDO $pdo,string $sql):PDOStatement
    {
        $this->check();
        if(preg_match('/\\ASELECT\\s/i',$sql)!==1)throw new InvalidArgumentException('forecast_status_select_only');
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            $sql=preg_replace('/\\ASELECT\\s/i','SELECT /*+ MAX_EXECUTION_TIME('.$this->remainingMilliseconds().') */ ',$sql,1);
        }
        $query=$pdo->prepare($sql);$this->check();return $query;
    }
}
