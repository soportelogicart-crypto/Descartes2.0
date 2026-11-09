const path = require('path')
const modulo = process.argv[2]
const texto = process.argv[3] || ''
const QRCode = require(path.resolve(modulo))
QRCode.toBuffer(texto, { type: 'png', margin: 1, width: 240 }, (err, buf) => {
  if (err || !buf) {
    process.exit(1)
  }
  process.stdout.write(buf)
})
