export type ChecklistMeta={required:boolean;section:string;sortOrder:number;sourceId:string;requiresValue:boolean;valueType:"temperature"|null;requiresNoteOnFail:boolean};
export const temperatureLabels=["Lodówka produkcyjna 1","Lodówka produkcyjna 2","Zamrażarka"];
export function readChecklist(raw:string|null|undefined):ChecklistMeta|null{if(!raw)return null;try{return JSON.parse(raw);}catch{return null;}}
