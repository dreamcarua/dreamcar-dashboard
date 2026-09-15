import re,subprocess,tempfile,os
s=open('docs/index.html',encoding='utf-8').read()
bad=0
for i,(attrs,body) in enumerate(re.findall(r'<script\b([^>]*)>(.*?)</script>', s, re.S),1):
    if 'src=' in attrs: continue
    f=tempfile.NamedTemporaryFile('w',suffix='.mjs',delete=False,encoding='utf-8'); f.write(body); f.close()
    r=subprocess.run(['node','--check',f.name],capture_output=True,text=True)
    print('block %d: %s' % (i,'OK' if r.returncode==0 else 'FAIL'))
    if r.returncode:
        print(r.stderr[:900]); bad=1
    os.unlink(f.name)
raise SystemExit(bad)
