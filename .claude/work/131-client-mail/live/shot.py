import json,re
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b=p.chromium.launch(headless=True)
    pg=b.new_page(viewport={'width':1080,'height':1920})
    pg.goto('http://127.0.0.1:8787/cockpit.php',wait_until='domcontentloaded')
    pg.wait_for_timeout(6000)
    pg.screenshot(path='full.png',full_page=True)
    c=pg.query_selector('.email-card')
    if c:
        c.scroll_into_view_if_needed(); c.screenshot(path='mail-card.png')
        d=pg.evaluate('''()=>{const c=document.querySelector('.email-card');const r=c.getBoundingClientRect();
        const items=[...c.querySelectorAll('.email-item')].map(i=>{const b=i.getBoundingClientRect();return [Math.round(b.left),Math.round(b.right),Math.round(b.top),Math.round(b.bottom),i.scrollWidth>i.clientWidth+1]});
        const tags=[...c.querySelectorAll('.email-tag')].map(t=>t.textContent);
        const un=c.querySelector('.email-unsure');
        return {card:[r.left,r.right,r.top,r.bottom],docW:document.documentElement.scrollWidth,tags,unsure:un?un.querySelector('summary').textContent:null,unsureOpen:un?un.open:null,
         btns:[...c.querySelectorAll('button')].map(b=>b.textContent),
         head:c.querySelector('.card-head').textContent,items,text:c.innerText.replace(/[\w.+-]+@[\w.-]+/g,'<addr>')}}''')
        print(json.dumps(d,indent=1))
        un=pg.query_selector('.email-unsure')
        if un:
            pg.evaluate("document.querySelector('.email-unsure').open=true")
            pg.wait_for_timeout(300)
            c.screenshot(path='mail-card-unsorted-open.png')
            print(pg.evaluate("[...document.querySelectorAll('.email-unsure button')].map(b=>b.textContent).slice(0,6)"))
    else: print('no card', pg.inner_text('body')[:300])
    b.close()
