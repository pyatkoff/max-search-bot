import sys
import contract10 as c
import site_source10 as s
p=c.proof()
if sys.argv[1]=='plan':
    before=c.read(c.BASE/'site-before.json')
    cards={h['id']:h for h in before['hotels']}
    c.need(all(cards[h['id']]==h for h in c.read(c.BASE/'selected-source.json')),'original_selected_cards_changed')
    plan=s.build_plan(before,p)
    c.save_new(c.ROOT/'plan.json',plan)
    print({'ready':True,'version':before['version'],'fourEdits':len(plan['edits']),'oneImport':True})
elif sys.argv[1]=='apply':
    m=c.source_client();_,receipt=s.apply(m.Client(m.hidden_credentials()),c.ROOT/'execution',p,c.read(c.ROOT/'plan.json'))
    print(receipt)
