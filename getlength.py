import sys
import os
import mutagen.mp3
from mutagen.mp3 import MP3
if len(sys.argv) < 2:
	print("Music file name cannot be empty!")
	exit(1)
if os.path.isfile(sys.argv[1]):
	try:
		audio = MP3(sys.argv[1])
		print(audio.info.length)
	except mutagen.mp3.HeaderNotFoundError:
		print(0)
else:
	print(0)
