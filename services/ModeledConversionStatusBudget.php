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
    public function remainingBytes():int{$this->check();return self::MAX_BYTES-$this->bytes;}
    public function consumeBytes(int $bytes):void
    {
        if($bytes<0||$bytes>$this->remainingBytes())throw new ModeledConversionStatusLimit('byte_limit');
        $this->bytes+=$bytes;$this->check();
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
