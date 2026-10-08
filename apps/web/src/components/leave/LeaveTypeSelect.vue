<script setup>
import {computed,onBeforeUnmount,onMounted,ref} from 'vue'
import {faCheck,faChevronDown} from '@fortawesome/free-solid-svg-icons'
import {getLeaveTypeIcon} from '@/utils/leaveTypeIcons'
const p=defineProps({modelValue:{type:[String,Number],default:''},options:{type:Array,default:()=>[]},disabled:Boolean})
const e=defineEmits(['update:modelValue']),open=ref(false),root=ref(null)
const selected=computed(()=>p.options.find(o=>String(o.id??o.value)===String(p.modelValue))||null)
const pick=o=>{e('update:modelValue',String(o.id??o.value));open.value=false}
const outside=x=>{if(!root.value?.contains(x.target))open.value=false}
onMounted(()=>document.addEventListener('click',outside));onBeforeUnmount(()=>document.removeEventListener('click',outside))
</script>
<template><div ref="root" class="lts">
<button class="lts__trigger" type="button" :disabled="disabled" @click="open=!open">
<span v-if="selected" class="lts__value"><span class="lts__icon" :style="{backgroundColor:selected.colour||'#777'}"><FontAwesomeIcon :icon="getLeaveTypeIcon(selected.icon)" :style="{color:selected.icon_colour==='black'?'#090909':'#fff'}"/></span>{{selected.label}}</span>
<span v-else>Choose leave type</span><FontAwesomeIcon :icon="faChevronDown"/>
</button>
<div v-if="open" class="lts__menu"><button v-for="o in options" :key="o.id??o.value" type="button" class="lts__option" @click="pick(o)">
<span class="lts__icon" :style="{backgroundColor:o.colour||'#777'}"><FontAwesomeIcon :icon="getLeaveTypeIcon(o.icon)" :style="{color:o.icon_colour==='black'?'#090909':'#fff'}"/></span><span>{{o.label}}</span><FontAwesomeIcon v-if="String(o.id??o.value)===String(modelValue)" :icon="faCheck"/>
</button></div></div></template>
<style scoped>
.lts{position:relative;width:100%}.lts__trigger{display:flex;width:100%;min-height:44px;align-items:center;justify-content:space-between;gap:12px;padding:7px 12px;border:1px solid #373737;background:#151515;color:#eee9e3;cursor:pointer}.lts__value{display:flex;align-items:center;gap:10px;font-size:11px}.lts__icon{display:grid;width:29px;height:29px;flex:0 0 29px;place-items:center}.lts__icon svg{font-size:13px}.lts__menu{position:absolute;z-index:90;top:calc(100% + 5px);right:0;left:0;max-height:300px;overflow-y:auto;border:1px solid #393939;background:#151515;box-shadow:0 20px 45px #0008}.lts__option{display:grid;width:100%;grid-template-columns:29px 1fr 16px;align-items:center;gap:10px;min-height:48px;padding:8px 11px;border:0;border-bottom:1px solid #282828;background:transparent;color:#ddd7d1;text-align:left;cursor:pointer}.lts__option:hover{background:#1d1d1d}
</style>
