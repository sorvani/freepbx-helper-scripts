#!/usr/bin/env python
import subprocess
extension = {}
output = []
output.append('<?xml version="1.0" encoding="utf-8"?>')
output.append('<CompanyIPPhoneDirectory>')
p = subprocess.Popen(["asterisk", "-rx", "database show"], stdout=subprocess.PIPE)
out =  p.communicate()
out = out[0].splitlines()
for line in out:
    if line.startswith('/AMPUSER'):
        if line.find('/cidname') > 1:
            key,value = line.split(':')
            key = key.strip()
            key = key.split('/')[2]
            value = value.strip()
            extension[key] = value
            output.append('    <DirectoryEntry>')
            output.append('        <Name>' + value + '</Name>')
            output.append('        <Telephone>' + key + '</Telephone>')
            output.append('    </DirectoryEntry>')
output.append('</CompanyIPPhoneDirectory>')

contactfile = open('/var/www/html/contacts.xml', 'w')
for item in output:
    contactfile.write("%s\n" % item)
