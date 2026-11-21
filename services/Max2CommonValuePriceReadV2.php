<?php
declare(strict_types=1);

/** Bounded read projection of the accepted v1 price-continuity rule. No v1 writes. */
final class Max2CommonValuePriceReadV2
{
    public const BASIS_SOURCE_SHA256 = '18f1101b3822bb02fd62b025c341a6fc5a298a1861fff04d8d668823848accbf';
    public const VERSION = 'max2_bounded_saved_price_continuity_v2';
    public function __construct(private PDO $pdo) {}

    public function adjust(array $wrapper, array $candidate, string $profileId): array
    {
        if (!is_string($profileId) || preg_match('/\A[1-9][0-9]{0,19}\z/D',$profileId) !== 1
            || hash_file('sha256',__DIR__.'/ModeledConversionPriceContinuity.php') !== self::BASIS_SOURCE_SHA256) {
            throw new RuntimeException('V2_PRICE_RULE_SOURCE_UNPROVED');
        }
        require_once __DIR__.'/ModeledConversionValueContract.php';
        $decision = ['candidate'=>$candidate,'basis'=>null];
        if (empty($candidate['prediction_value'])) return $decision;
        $money = ModeledConversionDealValue::saved($wrapper);
        if ($money === null) throw new RuntimeException('V2_PRICE_CAPSULE_UNPROVED');
        if ($money['deal_amount_rub']['point'] !== null) return $decision;
        $scope = $wrapper['subject']; $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $safe = "CASE WHEN JSON_VALID(p.profile_json) THEN p.profile_json ELSE '{}' END";
        $len = $mysql ? 'OCTET_LENGTH(p.profile_json)' : 'LENGTH(CAST(p.profile_json AS BLOB))';
        $point = "JSON_EXTRACT(".$safe.",'$.deal_value_prediction.deal_amount_rub.point')";
        $access = "JSON_EXTRACT(".$safe.",'$.access_status')";
        if ($mysql) $access = 'JSON_UNQUOTE('.$access.')';
        $type = $mysql ? 'JSON_TYPE('.$point.')' : "json_type(".$safe.",'$.deal_value_prediction.deal_amount_rub.point')";
        $fields = ['p.id','p.customer_key',"CASE WHEN $len<=131072 THEN 1 ELSE 0 END AS packet_within_bound"];
        foreach (['subject_json'=>"JSON_EXTRACT(".$safe.",'$.subject')",'access_value'=>$access,
            'prediction_json'=>"JSON_EXTRACT(".$safe.",'$.semantic_prediction')",
            'money_json'=>"JSON_EXTRACT(".$safe.",'$.deal_value_prediction')"] as $alias=>$value) {
            $fields[] = "CASE WHEN $len<=131072 THEN $value ELSE NULL END AS $alias";
        }
        $sql = 'SELECT '.($mysql?'/*+ MAX_EXECUTION_TIME(250) */ ':'').implode(',',$fields)
            . ' FROM max_channel_conversion_outbox o JOIN modeled_conversion_profile_versions p ON p.id=o.value_profile_id'
            . " WHERE p.project_key='anytour' AND p.platform=? AND p.conversation_id=? AND p.id<?"
            . " AND o.project_key='anytour' AND o.counter_id='98615635' AND o.transport_target=? AND o.value_contract=?"
            . " AND o.conversation_id=? AND o.platform=? AND o.value_rub>0 AND $type=".($mysql?"'INTEGER'":"'integer'")
            . ' ORDER BY o.id DESC LIMIT 8';
        $q = $this->pdo->prepare($sql);
        $q->execute([$scope['platform'],$scope['conversation_id'],$profileId,ModeledConversionValueContract::TARGET,
            ModeledConversionValueContract::VERSION,$scope['conversation_id'],$scope['platform']]);
        $references = $q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor();
        $bytes = 0;
        foreach ($references as $ref) {
            // Keep an oversized earlier candidate visible as UNKNOWN, never silently
            // filter it out and let an unpriced larger value replace it.
            if ((string)$ref['packet_within_bound'] !== '1') throw new RuntimeException('V2_PRICE_REFERENCE_BOUND');
            foreach (['subject_json','prediction_json','money_json'] as $key) $bytes += strlen((string)($ref[$key] ?? ''));
            if ($bytes > 131072) throw new RuntimeException('V2_PRICE_REFERENCE_BOUND');
            try {
                $previous = ['access_status'=>$ref['access_value'] ?? null,
                    'subject'=>json_decode($ref['subject_json'] ?? 'null',true,32,JSON_THROW_ON_ERROR),
                    'semantic_prediction'=>json_decode($ref['prediction_json'] ?? 'null',true,32,JSON_THROW_ON_ERROR),
                    'deal_value_prediction'=>json_decode($ref['money_json'] ?? 'null',true,32,JSON_THROW_ON_ERROR)];
            } catch (JsonException $ignored) { continue; }
            if ((string)$ref['customer_key'] !== $scope['customer_key']
                || !ModeledConversionValueContract::sameSubject($previous['subject'],$scope)
                || !is_array($previous['semantic_prediction'])
                || !ModeledConversionSemanticPrediction::digestMatches($previous['semantic_prediction'])) continue;
            $priced = ModeledConversionDealValue::saved($previous);
            if ($priced === null || !is_int($priced['deal_amount_rub']['point'])) continue;
            $base = min($money['full_value_base_rub'],$priced['full_value_base_rub']);
            if ($base >= $money['full_value_base_rub']) return $decision;
            $p = $wrapper['semantic_prediction'];
            $predictive = ($p['mode'] ?? null) === 'contextual_advertising_v1' && ($p['status'] ?? null) === 'predicted';
            $weight = $predictive ? $p['estimated_probability'] : ModeledConversionValueContract::VALUES[$candidate['rank']]/25000;
            $adjusted = (int)round($weight*$base);
            $decision['basis'] = ['version'=>self::VERSION,'basis_source_sha256'=>self::BASIS_SOURCE_SHA256,
                'reason'=>'missing_current_price_retains_prior_priced_value_cap','reference_profile_id'=>(string)$ref['id'],
                'reference_deal_rub'=>$priced['deal_amount_rub']['point'],'reference_base_rub'=>$priced['full_value_base_rub'],
                'frozen_base_rub'=>$money['full_value_base_rub'],'applied_base_rub'=>$base,'weight'=>$weight,
                'weight_basis'=>$predictive?'frozen_probability':'stage_fraction','frozen_target_rub'=>$candidate['value'],
                'applied_target_rub'=>$adjusted];
            $decision['candidate']['value'] = $adjusted;
            return $decision;
        }
        return $decision;
    }
}
