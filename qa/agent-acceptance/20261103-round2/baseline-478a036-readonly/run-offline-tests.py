import os, sys, pathlib, tempfile, unittest
root=pathlib.Path('D:/CodexDeploy/summon-mecha')
out=pathlib.Path(__file__).resolve().parent
(out/'tmp').mkdir(exist_ok=True)
tempfile.tempdir=str(out/'tmp')
sys.path.insert(0,str(root/'agent'))
suite=unittest.defaultTestLoader.discover(str(root/'agent/tests'), pattern='test_*.py')
def cases(s):
    for item in s:
        if isinstance(item,unittest.TestSuite): yield from cases(item)
        elif 'test_live_http_integration' not in item.id(): yield item
with (out/'python-offline.txt').open('w',encoding='utf-8') as log:
    result=unittest.TextTestRunner(stream=log,verbosity=2).run(unittest.TestSuite(list(cases(suite))))
print('tests',result.testsRun,'errors',len(result.errors),'failures',len(result.failures),'skipped',len(result.skipped))
sys.exit(0 if result.wasSuccessful() else 1)
