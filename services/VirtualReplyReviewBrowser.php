<?php
declare(strict_types=1);

require_once __DIR__.'/VirtualReplyStore.php';

/** Owner-only ordinary-review projection. No controls, claims, generation or messages. */
final class VirtualReplyReviewBrowser
{
    public function __construct(private $store) {}

    public function read(array $actor,array $request): array
    {
        if (($actor['role']??'')!=='admin' || empty($actor['is_active']) || (int)($actor['id']??0)<=0
            || !in_array('anytour',array_column($actor['projects']??[],'project_key'),true)) {
            return ['ok'=>false,'error'=>'forbidden'];
        }
        if (array_diff(array_keys($request),['cursor','verdict','case_id'])!==[]) throw new InvalidArgumentException();
        if (array_key_exists('case_id',$request)) {
            if (count($request)!==1 || !is_string($request['case_id']) || !preg_match('/\A[a-f0-9]{64}\z/D',$request['case_id'])) throw new InvalidArgumentException();
            $d=$this->store->review($request['case_id']);
            // Never serialize contracts, claim tokens, provider metadata or source identity.
            return ['ok'=>true,'case_id'=>$d['case_id'],'payload'=>$d['payload'],
                'author_result'=>$d['author_result'],'review_result'=>$d['review_result'],'disposition'=>$d['disposition']];
        }
        $cursor=$request['cursor']??null;$verdict=$request['verdict']??'all';
        if (($cursor!==null&&!is_string($cursor)) || !is_string($verdict)) throw new InvalidArgumentException();
        return ['ok'=>true]+$this->store->reviewPage($cursor,$verdict);
    }
}
