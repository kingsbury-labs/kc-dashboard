from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b=p.chromium.launch(headless=True); pg=b.new_page(viewport={'width':1080,'height':1920})
    pg.goto('http://127.0.0.1:8787/cockpit.php',wait_until='domcontentloaded'); pg.wait_for_timeout(6000)
    print(pg.evaluate('''()=>{const c=document.querySelector('.email-card');const s=x=>{const q=getComputedStyle(x);return [x.className,q.overflow,q.overflowY,Math.round(x.clientHeight),x.scrollHeight]};
    const u=c.querySelector('.email-unsure').getBoundingClientRect();const cr=c.getBoundingClientRect();
    return {card:s(c),list:s(c.querySelector('.email-list')),parent:s(c.parentElement),cardBottom:cr.bottom,unsureTop:u.top,unsureBottom:u.bottom}}'''))
    b.close()
