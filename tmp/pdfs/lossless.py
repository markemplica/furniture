from pathlib import Path
from io import BytesIO
import struct
from PIL import Image
from pypdf import PdfReader,PdfWriter
from pypdf.generic import NameObject,NumberObject,DictionaryObject
src=Path(r'C:\Users\User\Downloads\-CAYABYAB_ASHLEY_ITE04_PREFINAL-LAB-ACTIVITY.pdf')
out=Path('output/pdf/CAYABYAB_ASHLEY_ITE04_PREFINAL-LAB-ACTIVITY-clear-under-10MB.pdf')
r=PdfReader(src); w=PdfWriter(clone_from=r)
count=0;saved=0
for o in w._objects:
 if not isinstance(o,DictionaryObject) or o.get('/Subtype')!='/Image' or o.get('/BitsPerComponent')!=8:continue
 width=int(o['/Width']);height=int(o['/Height']);raw=o.get_data();channels=len(raw)//(width*height)
 if channels not in (1,3) or len(raw)!=width*height*channels:continue
 im=Image.frombytes({1:'L',3:'RGB'}[channels],(width,height),raw);buf=BytesIO();im.save(buf,format='PNG',optimize=True,compress_level=9)
 png=buf.getvalue();pos=8;data=b''
 while pos<len(png):
  n=struct.unpack('>I',png[pos:pos+4])[0]
  if png[pos+4:pos+8]==b'IDAT':data+=png[pos+8:pos+8+n]
  pos+=n+12
 if len(data)>=len(o._data):continue
 saved+=len(o._data)-len(data);count+=1
 o._data=data;o[NameObject('/Filter')]=NameObject('/FlateDecode')
 o[NameObject('/DecodeParms')]=DictionaryObject({NameObject('/Predictor'):NumberObject(15),NameObject('/Colors'):NumberObject(channels),NameObject('/BitsPerComponent'):NumberObject(8),NameObject('/Columns'):NumberObject(width)})
 assert o.get_data()==raw
w.write(out)
print('Optimized',count,'images; bytes saved',saved,'size',out.stat().st_size,flush=True)
c=PdfReader(out)
assert len(c.pages)==len(r.pages)
assert all(a.extract_text()==b.extract_text() for a,b in zip(r.pages,c.pages))
print('Pages and text verified',flush=True)
