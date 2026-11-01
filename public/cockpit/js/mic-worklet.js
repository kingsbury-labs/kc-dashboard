// Card 110: mic audio down to 16 kHz mono int16, posted in 512-sample chunks for cas-voice. From cas-voice/proto/index.html.
class Down extends AudioWorkletProcessor {
  constructor() {
    super();
    this.r = sampleRate / 16000;
    this.pos = 0;
    this.acc = 0;
    this.cnt = 0;
    this.out = new Int16Array(512);
    this.n = 0;
  }

  process(inputs) {
    const ch = inputs[0] && inputs[0][0];
    if (!ch) return true;
    for (let i = 0; i < ch.length; i++) {
      this.acc += ch[i];
      this.cnt++;
      this.pos += 1;
      if (this.pos >= this.r) {
        const v = Math.max(-1, Math.min(1, this.acc / this.cnt));
        this.out[this.n++] = v < 0 ? v * 32768 : v * 32767;
        this.acc = 0;
        this.cnt = 0;
        this.pos -= this.r;
        if (this.n === 512) {
          this.port.postMessage(this.out.buffer.slice(0));
          this.n = 0;
        }
      }
    }
    return true;
  }
}
registerProcessor('down', Down);
